<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ScanLog;
use App\Models\Weaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssociationController extends Controller
{
    public function weavers(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $status = $this->statusFilter($request);

        $weavers = $user->association
            ->weavers()
            ->with('products')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->get();

        return response()->json([
            'weavers' => $weavers,
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $status = $this->statusFilter($request);

        $products = Product::whereHas('weaver', function ($query) use ($user) {
            $query->where('association_id', $user->association_id);
        })
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with('weaver')
            ->get();

        return response()->json([
            'products' => $products,
        ]);
    }

    public function storeWeaver(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'proprietor' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
        ]);

        $weaver = Weaver::create([
            'association_id' => $user->association_id,
            'name' => $validated['name'],
            'proprietor' => $validated['proprietor'],
            'municipality' => $validated['municipality'],
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Weaver created successfully.',
            'weaver' => $weaver,
        ], 201);
    }
    
    public function updateWeaver(Request $request, Weaver $weaver): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        if ($weaver->association_id !== $user->association_id) {
            return response()->json([
                'message' => 'You are not authorized to modify this weaver.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'proprietor' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $weaver->update([
            'name' => $validated['name'],
            'proprietor' => $validated['proprietor'],
            'municipality' => $validated['municipality'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Weaver updated successfully.',
            'weaver' => $weaver->fresh(),
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
            'weaver_id' => ['required', 'integer', 'exists:weavers,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
        ]);

        $weaver = Weaver::where('id', $validated['weaver_id'])
            ->where('association_id', $user->association_id)
            ->first();

        if (!$weaver) {
            return response()->json([
                'message' => 'The selected weaver does not belong to your association.',
            ], 403);
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
            'product' => $product->load('weaver'),
        ], 201);
    }

    /**
     * Edit a product of this association, including deactivating or reactivating it.
     * The weaver cannot be changed here; a product of an inactive weaver can be
     * edited or deactivated but not reactivated.
     */
    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        if (!$user->association_id) {
            return response()->json([
                'message' => 'Association account is not linked to an association.',
            ], 403);
        }

        $product->loadMissing('weaver');

        if ((int) $product->weaver->association_id !== (int) $user->association_id) {
            return response()->json([
                'message' => 'You are not authorized to modify this product.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if ($validated['status'] === 'active' && $product->weaver->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'The weaver of this product is inactive.',
            ]);
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product->fresh()->load('weaver'),
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

        $logs = ScanLog::whereHas('nfcTag.product.weaver', function ($query) use ($user) {
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
    private function statusFilter(Request $request): string
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive,all'],
        ]);

        return $validated['status'] ?? 'active';
    }
}
