<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Product;
use App\Models\Weaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Staff (and admin) management of associations, weavers and products.
 *
 * Staff are limited to associations in their own municipality; admin sees
 * everything. Out-of-scope records return 403, an inactive weaver returns 422.
 */
class StaffController extends Controller
{
    public function associations(Request $request): JsonResponse
    {
        $associations = Association::manageableBy($request->user())
            ->withCount('weavers')
            ->orderBy('name')
            ->get();

        return response()->json([
            'associations' => $associations,
        ]);
    }

    public function storeAssociation(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
        ]);

        if ($user->role === 'staff' && strcasecmp($validated['municipality'], (string) $user->municipality) !== 0) {
            return $this->forbidden('You can only register associations in your own municipality.');
        }

        $association = Association::create([
            'name' => $validated['name'],
            // Staff are matched by exact municipality, so store their canonical spelling.
            'municipality' => $user->role === 'staff' ? $user->municipality : $validated['municipality'],
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Association created successfully.',
            'association' => $association,
        ], 201);
    }

    public function weavers(Request $request): JsonResponse
    {
        $weavers = Weaver::manageableBy($request->user())
            ->with('association')
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
            'municipality' => ['required', 'string', 'max:255'],
        ]);

        $association = Association::manageableBy($request->user())->find($validated['association_id']);

        if (! $association) {
            return $this->forbidden('You cannot manage this association.');
        }

        $weaver = Weaver::create([
            'association_id' => $association->id,
            'name' => $validated['name'],
            'municipality' => $validated['municipality'],
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Weaver created successfully.',
            'weaver' => $weaver->load('association'),
        ], 201);
    }

    public function updateWeaver(Request $request, Weaver $weaver): JsonResponse
    {
        $user = $request->user();

        if (! Weaver::manageableBy($user)->whereKey($weaver->id)->exists()) {
            return $this->forbidden('You cannot manage this weaver.');
        }

        $validated = $request->validate([
            'association_id' => ['required', 'integer', 'exists:associations,id'],
            'name' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $association = Association::manageableBy($user)->find($validated['association_id']);

        if (! $association) {
            return $this->forbidden('You cannot manage this association.');
        }

        $weaver->update([
            'association_id' => $association->id,
            'name' => $validated['name'],
            'municipality' => $validated['municipality'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Weaver updated successfully.',
            'weaver' => $weaver->fresh()->load('association'),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $products = Product::manageableBy($request->user())
            ->with('weaver.association')
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

        $weaver = Weaver::manageableBy($request->user())->find($validated['weaver_id']);

        if (! $weaver) {
            return $this->forbidden('You cannot manage this weaver.');
        }

        if ($weaver->status !== 'active') {
            throw ValidationException::withMessages([
                'weaver_id' => 'The selected weaver is inactive.',
            ]);
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

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        if (! Product::manageableBy($user)->whereKey($product->id)->exists()) {
            return $this->forbidden('You cannot manage this product.');
        }

        $validated = $request->validate([
            'weaver_id' => ['required', 'integer', 'exists:weavers,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $weaver = Weaver::manageableBy($user)->find($validated['weaver_id']);

        if (! $weaver) {
            return $this->forbidden('You cannot manage this weaver.');
        }

        // An inactive weaver may not receive products or have products reactivated,
        // but its existing products can still be edited or deactivated.
        $movingOrActivating = $weaver->id !== $product->weaver_id || $validated['status'] === 'active';

        if ($weaver->status !== 'active' && $movingOrActivating) {
            throw ValidationException::withMessages([
                'weaver_id' => 'The selected weaver is inactive.',
            ]);
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

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 403);
    }
}
