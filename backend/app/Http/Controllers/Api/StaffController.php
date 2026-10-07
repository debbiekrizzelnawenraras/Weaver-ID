<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Weaver;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function weavers(): JsonResponse
    {
        $weavers = Weaver::with('association')
            ->withCount('products')
            ->get();

        return response()->json([
            'weavers' => $weavers,
        ]);
    }

    public function storeWeaver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'association_id' => ['required', 'integer', 'exists:associations,id'],
            'name' => ['required', 'string', 'max:255'],
            'proprietor' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
        ]);

        $weaver = Weaver::create([
            'association_id' => $validated['association_id'],
            'name' => $validated['name'],
            'proprietor' => $validated['proprietor'],
            'municipality' => $validated['municipality'],
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Weaver created successfully.',
            'weaver' => $weaver->load('association'),
        ], 201);
    }

    public function updateWeaver(
        Request $request,
        Weaver $weaver
    ): JsonResponse {
        $validated = $request->validate([
            'association_id' => ['required', 'integer', 'exists:associations,id'],
            'name' => ['required', 'string', 'max:255'],
            'proprietor' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $weaver->update([
            'association_id' => $validated['association_id'],
            'name' => $validated['name'],
            'proprietor' => $validated['proprietor'],
            'municipality' => $validated['municipality'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Weaver updated successfully.',
            'weaver' => $weaver->fresh()->load('association'),
        ]);
    }

    public function products(): JsonResponse
{
    $products = Product::with('weaver.association')
        ->get();

    return response()->json([
        'products' => $products,
    ]);
}

public function storeProduct(Request $request): JsonResponse
{
    $validated = $request->validate([
        'weaver_id' => ['required', 'integer', 'exists:weavers,id'],
        'title' => ['required', 'string', 'max:255'],
        'category' => ['required', 'string', 'max:255'],
    ]);

    $weaver = Weaver::where('id', $validated['weaver_id'])
        ->where('status', 'active')
        ->first();

    if (!$weaver) {
        return response()->json([
            'message' => 'The selected weaver does not exist or is inactive.',
        ], 403);
    }

    $product = Product::create([
        'weaver_id' => $weaver->id,
        'title' => $validated['title'],
        'category' => $validated['category'],
        'status' => 'active',
    ]);

    return response()->json([
        'message' => 'Product created successfully.',
        'product' => $product->load('weaver.association'),
    ], 201);
}

public function updateProduct(
        Request $request,
        Product $product
    ): JsonResponse {
        $validated = $request->validate([
            'weaver_id' => ['required', 'integer', 'exists:weavers,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $weaver = Weaver::where('id', $validated['weaver_id'])
            ->where('status', 'active')
            ->first();

        if (!$weaver) {
            return response()->json([
                'message' => 'The selected weaver does not exist or is inactive.',
            ], 403);
        }

        $product->update([
            'weaver_id' => $weaver->id,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh()->load('weaver.association'),
        ]);
    }

}