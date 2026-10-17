<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\NfcTagEvent;
use App\Models\Product;
use App\Support\TagSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tag management for admin, staff and association users.
 *
 * Every query is limited to what the signed-in user may manage:
 * admin everything, staff their municipality, association its own products.
 */
class NfcTagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = NfcTag::manageableBy($request->user())
            ->with('product.weaver.association')
            ->latest('id')
            ->get();

        return response()->json([
            'tags' => $tags,
        ]);
    }

    /**
     * Issue a tag: register its UID and link it to a registered product.
     */
    public function store(Request $request): JsonResponse
    {
        $uid = $request->input('uid');

        if (is_string($uid)) {
            $request->merge(['uid' => NfcTag::normalizeUid($uid)]);
        }

        $validated = $request->validate([
            'uid' => [
                'required',
                'string',
                'regex:/^[0-9A-F]{8,20}$/',
                Rule::unique('nfc_tags', 'uid'),
            ],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('status', 'active'),
                Rule::unique('nfc_tags', 'product_id')->where('status', 'active'),
            ],
        ], [
            'uid.unique' => 'This tag UID is already registered.',
            'product_id.unique' => 'This product already has an active tag. Revoke it first to issue a replacement.',
            'product_id.exists' => 'The selected product does not exist or is inactive.',
        ]);

        $product = Product::manageableBy($request->user())
            ->with('weaver')
            ->find($validated['product_id']);

        if (! $product) {
            return response()->json([
                'message' => 'You cannot issue tags for this product.',
            ], 403);
        }

        if ($product->weaver->status !== 'active') {
            throw ValidationException::withMessages([
                'product_id' => 'The weaver for this product is inactive.',
            ]);
        }

        $tag = NfcTag::create([
            'uid' => $validated['uid'],
            'product_id' => $product->id,
            'status' => 'active',
            'issued_by' => $request->user()->id,
            'issued_at' => now(),
        ]);

        return response()->json([
            'message' => 'NFC tag issued successfully.',
            'tag' => $tag->load('product.weaver.association'),
        ], 201);
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

        $nfcTag->update([
            'status' => 'revoked',
        ]);

        return response()->json([
            'message' => 'NFC tag revoked.',
            'tag' => $nfcTag->fresh()->load('product.weaver.association'),
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
            'tag' => $nfcTag->fresh()->load('product.weaver.association'),
        ]);
    }
}
