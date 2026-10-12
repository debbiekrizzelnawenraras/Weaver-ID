<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\Product;
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
}
