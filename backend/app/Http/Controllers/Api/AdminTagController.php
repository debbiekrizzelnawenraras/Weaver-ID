<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NfcTag;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminTagController extends Controller
{
    /**
     * Fix a mistake: move a tag to a different product.
     *
     * A revoked tag stays revoked unless the admin passes reactivate=true,
     * because revoking means "do not trust this tag". Every change is
     * written to nfc_tag_events.
     */
    public function reassign(Request $request, NfcTag $nfcTag): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('status', 'active'),
                Rule::unique('nfc_tags', 'product_id')
                    ->where('status', 'active')
                    ->ignore($nfcTag->id),
            ],
            'reactivate' => ['sometimes', 'boolean'],
        ], [
            'product_id.exists' => 'The selected product does not exist or is inactive.',
            'product_id.unique' => 'That product already has an active tag. Revoke it first.',
        ]);

        $reactivate = $request->boolean('reactivate');
        $wasRevoked = $nfcTag->status === 'revoked';

        if ($wasRevoked && ! $reactivate) {
            throw ValidationException::withMessages([
                'reactivate' => 'This tag is revoked. Send reactivate=true to put it back in service.',
            ]);
        }

        if (! $wasRevoked && (int) $nfcTag->product_id === (int) $validated['product_id']) {
            throw ValidationException::withMessages([
                'product_id' => 'This tag is already linked to that product.',
            ]);
        }

        $product = Product::with('weaver')->findOrFail($validated['product_id']);

        if ($product->weaver->status !== 'active') {
            throw ValidationException::withMessages([
                'product_id' => 'The weaver for this product is inactive.',
            ]);
        }

        $fromProductId = $nfcTag->product_id;

        DB::transaction(function () use ($nfcTag, $product, $request, $fromProductId, $wasRevoked) {
            $nfcTag->update([
                'product_id' => $product->id,
                'status' => 'active',
            ]);

            $nfcTag->events()->create([
                'user_id' => $request->user()->id,
                'action' => $wasRevoked ? 'reactivated' : 'reassigned',
                'from_product_id' => $fromProductId,
                'to_product_id' => $product->id,
            ]);
        });

        return response()->json([
            'message' => $wasRevoked ? 'NFC tag reactivated and reassigned.' : 'NFC tag reassigned.',
            'tag' => $nfcTag->fresh()->load('product.weaver.association'),
        ]);
    }
}
