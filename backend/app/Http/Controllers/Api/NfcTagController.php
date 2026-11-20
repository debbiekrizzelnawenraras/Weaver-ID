<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\NfcTagEvent;
use App\Models\Product;
use App\Models\User;
use App\Support\TagSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Linking, securing and revoking tags.
 *
 * Staff and association users link and secure tags; admin only lists and revokes
 * them (see routes/api.php). A tag must already be in the inventory: staff may link
 * a tag from the staff member's own Negosyo Center stock or one sent to the product's association, and an
 * association may link only tags sent to it.
 */
class NfcTagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Tags still in a Negosyo Center's stock are listed by the Inventory tab, page by page, so this
        // list leaves them out unless ?include=stock is asked for. With hundreds of tags in stock, the
        // NFC Tags page no longer downloads them only to hide them.
        $withStock = $request->query('include') === 'stock';

        $tags = NfcTag::manageableBy($request->user())
            ->when(! $withStock, fn ($q) => $q->whereNotNull('association_id'))
            ->with('product', 'association', 'batch:id,code')
            ->latest('id')
            ->get();

        return response()->json([
            'tags' => $tags,
        ]);
    }

    /**
     * Link a tag from the inventory to a registered product.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $uid = $request->input('uid');

        if (is_string($uid)) {
            $request->merge(['uid' => NfcTag::normalizeUid($uid)]);
        }

        $validated = $request->validate([
            'uid' => ['required', 'string', 'regex:/^[0-9A-F]{8,20}$/'],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('status', 'active'),
                Rule::unique('nfc_tags', 'product_id')->where('status', 'active'),
            ],
        ], [
            'product_id.unique' => 'This product already has an active tag. Revoke it first to issue a replacement.',
            'product_id.exists' => 'The selected product does not exist or is inactive.',
        ]);

        $product = Product::manageableBy($user)
            ->with('association')
            ->find($validated['product_id']);

        if (! $product) {
            return response()->json([
                'message' => 'You cannot issue tags for this product.',
            ], 403);
        }

        if ($product->association->status !== 'active') {
            throw ValidationException::withMessages([
                'product_id' => 'The association of this product is inactive.',
            ]);
        }

        $tag = DB::transaction(function () use ($validated, $product, $user) {
            $tag = NfcTag::where('uid', $validated['uid'])->lockForUpdate()->first();

            if ($problem = $this->linkProblem($tag, $product, $user)) {
                throw ValidationException::withMessages(['uid' => $problem]);
            }

            $fromStock = $tag->status === 'in_stock';

            $tag->update([
                'product_id' => $product->id,
                'association_id' => $product->association_id,
                'status' => 'active',
                'issued_by' => $user->id,
                'issued_at' => now(),
            ] + ($fromStock ? ['sent_by' => $user->id, 'sent_at' => now()] : []));

            $tag->events()->create([
                'user_id' => $user->id,
                'action' => 'linked',
                'to_product_id' => $product->id,
                'association_id' => $product->association_id,
                'note' => $fromStock ? 'Linked straight from the Negosyo Center stock.' : null,
            ]);

            return $tag;
        });

        return response()->json([
            'message' => 'NFC tag issued successfully.',
            'tag' => $tag->fresh()->load('product', 'association', 'batch:id,code'),
        ], 201);
    }

    /** Why this tag cannot be linked to this product by this user, or null when it can. */
    private function linkProblem(?NfcTag $tag, Product $product, User $user): ?string
    {
        if (! $tag) {
            return 'This tag is not in the inventory. Staff must receive it into stock first.';
        }

        return match ($tag->status) {
            'active' => 'This tag UID is already registered.',
            'revoked' => 'This tag was revoked. Only the administrator can reactivate it.',
            'damaged' => 'This tag was marked damaged or lost and cannot be used.',
            'in_stock' => $user->role !== 'staff'
                ? 'This tag was not sent to your association. Ask DTI staff to send it first.'
                : ($tag->batch?->municipality === $user->municipality
                    ? null
                    : 'This tag is in the stock of another Negosyo Center, not yours.'),
            'sent' => (int) $tag->association_id === (int) $product->association_id
                ? null
                : ($user->role === 'association'
                    ? 'This tag was not sent to your association.'
                    : 'This tag was sent to another association, not to the association of this product.'),
            default => 'This tag cannot be linked.',
        };
    }

    public function revoke(Request $request, NfcTag $nfcTag): JsonResponse
    {
        $allowed = NfcTag::manageableBy($request->user())
            ->whereKey($nfcTag->id)
            ->exists();

        if (! $allowed) {
            return response()->json([
                'message' => 'You cannot revoke this tag.',
            ], 403);
        }

        if (! in_array($nfcTag->status, ['active', 'revoked'], true)) {
            return response()->json([
                'message' => 'Only a linked tag can be revoked. Use the inventory to mark an unused tag damaged or lost.',
            ], 422);
        }

        if ($nfcTag->status === 'active') {
            DB::transaction(function () use ($nfcTag, $request) {
                $nfcTag->update(['status' => 'revoked']);

                $nfcTag->events()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'revoked',
                    'from_product_id' => $nfcTag->product_id,
                ]);
            });
        }

        return response()->json([
            'message' => 'NFC tag revoked.',
            'tag' => $nfcTag->fresh()->load('product', 'association', 'batch:id,code'),
        ]);
    }

    /**
     * Give the tag desk the write password for one tag it is allowed to manage.
     * The password is derived from the UID and a server secret, never stored.
     */
    public function secureKey(Request $request, NfcTag $nfcTag): JsonResponse
    {
        if (! NfcTag::manageableBy($request->user())->whereKey($nfcTag->id)->exists()) {
            return response()->json(['message' => 'You cannot secure this tag.'], 403);
        }

        if ($nfcTag->status !== 'active') {
            return response()->json(['message' => 'Only an active tag can be secured.'], 422);
        }

        if (! TagSecurity::keyConfigured()) {
            return response()->json([
                'message' => 'The server has no tag key. Set WEAVER_TAG_KEY in the .env file first.',
            ], 503);
        }

        NfcTagEvent::create([
            'nfc_tag_id' => $nfcTag->id,
            'user_id' => $request->user()->id,
            'action' => 'key_issued',
        ]);

        return response()->json([
            'uid' => $nfcTag->uid,
            'code' => TagSecurity::code($nfcTag->uid),
        ] + TagSecurity::credentials($nfcTag->uid));
    }

    /**
     * The tag desk reports that the password and counter were written. The
     * counter it read becomes the baseline for later scans. Calling this again
     * (after securing the tag again at the desk) resets the baseline.
     */
    public function secured(Request $request, NfcTag $nfcTag): JsonResponse
    {
        if (! NfcTag::manageableBy($request->user())->whereKey($nfcTag->id)->exists()) {
            return response()->json(['message' => 'You cannot secure this tag.'], 403);
        }

        $validated = $request->validate([
            'counter' => ['required', 'integer', 'min:0', 'max:'.TagSecurity::MAX_COUNTER],
            // True when the tag desk also wrote the tag code onto the tag.
            'code_written' => ['sometimes', 'boolean'],
        ]);

        $nfcTag->update([
            'counter_enabled' => true,
            'code_enabled' => $request->boolean('code_written') || $nfcTag->code_enabled,
            'last_counter' => $validated['counter'],
            'secured_at' => now(),
        ]);

        NfcTagEvent::create([
            'nfc_tag_id' => $nfcTag->id,
            'user_id' => $request->user()->id,
            'action' => 'secured',
        ]);

        return response()->json([
            'message' => 'NFC tag secured.',
            'tag' => $nfcTag->fresh()->load('product', 'association', 'batch:id,code'),
        ]);
    }
}
