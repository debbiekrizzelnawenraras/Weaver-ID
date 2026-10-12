<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NfcTagController extends Controller
{
    public function index(): JsonResponse
    {
        $tags = NfcTag::with('product.weaver.association')
            ->latest()
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

        $product = Product::with('weaver')->findOrFail($validated['product_id']);

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

    public function revoke(NfcTag $nfcTag): JsonResponse
    {
        $nfcTag->update([
            'status' => 'revoked',
        ]);

        return response()->json([
            'message' => 'NFC tag revoked.',
            'tag' => $nfcTag->fresh()->load('product.weaver.association'),
        ]);
    }
}
