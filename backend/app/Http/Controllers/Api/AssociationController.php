<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ScanLog;
use App\Support\ProductPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssociationController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $status = $this->statusFilter($request);

        $products = Product::where('association_id', $user->association_id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with('latestPhotoEvent.user')
            ->get();

        return response()->json([
            'products' => $products,
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(Product::CATEGORIES)],
        ]);

        if ($user->association->status !== 'active') {
            return response()->json([
                'message' => 'Your association is inactive.',
            ], 403);
        }

        $product = Product::create([
            'association_id' => $user->association_id,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product,
        ], 201);
    }

    /**
     * Edit a product of this association, including deactivating or reactivating it.
     */
    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        if ((int) $product->association_id !== (int) $user->association_id) {
            return response()->json([
                'message' => 'You are not authorized to modify this product.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(Product::CATEGORIES)],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh(),
        ]);
    }

    /**
     * Scans of this association's own tags, newest first, 50 per page.
     * Scans of unknown tags are not shown because they belong to nobody.
     * Optional ?result=authenticated|unregistered|suspicious.
     */
    public function scanLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $validated = $request->validate([
            'result' => ['nullable', Rule::enum(ScanResult::class)],
        ]);

        $logs = ScanLog::whereHas('nfcTag', function ($query) use ($user) {
            $query->where('association_id', $user->association_id);
        })
            ->with('nfcTag.product')
            ->when($validated['result'] ?? null, fn ($query, $result) => $query->where('result', $result))
            ->latest('id')
            ->paginate(50);

        return response()->json($logs);
    }

    /**
     * ?status=active (default) | inactive | all, so deactivated records can be found and reactivated.
     */
    public function uploadProductPhoto(Request $request, Product $product): JsonResponse
    {
        if ($denied = $this->denyForeignProduct($request, $product)) {
            return $denied;
        }

        $request->validate(['photo' => ProductPhoto::rules()]);

        return response()->json([
            'message' => 'Product photo saved.',
            'product' => ProductPhoto::replace($product, $request->file('photo'), $request->user())->load('association', 'latestPhotoEvent.user'),
        ]);
    }

    public function deleteProductPhoto(Request $request, Product $product): JsonResponse
    {
        if ($denied = $this->denyForeignProduct($request, $product)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Product photo removed.',
            'product' => ProductPhoto::remove($product, $request->user())->load('association', 'latestPhotoEvent.user'),
        ]);
    }

    /** A 403 response unless the product belongs to the user's own association. */
    private function denyForeignProduct(Request $request, Product $product): ?JsonResponse
    {
        $user = $request->user();

        if (! $user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        if ((int) $product->association_id !== (int) $user->association_id) {
            return response()->json([
                'message' => 'You are not authorized to modify this product.',
            ], 403);
        }

        return null;
    }

    private function statusFilter(Request $request): string
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive,all'],
        ]);

        return $validated['status'] ?? 'active';
    }
}
