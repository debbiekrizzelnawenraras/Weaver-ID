<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Weaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssociationController extends Controller
{
    public function weavers(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'association' || !$user->association_id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $weavers = $user->association
            ->weavers()
            ->with('products')
            ->where('status', 'active')
            ->get();

        return response()->json([
            'weavers' => $weavers,
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'association' || !$user->association_id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $products = \App\Models\Product::whereHas('weaver', function ($query) use ($user) {
            $query->where('association_id', $user->association_id);
        })
            ->where('status', 'active')
            ->with('weaver')
            ->get();

        return response()->json([
            'products' => $products,
        ]);
    }

    public function storeWeaver(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'association' || !$user->association_id) {
            return response()->json([
                'message' => 'Unauthorized.',
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
}