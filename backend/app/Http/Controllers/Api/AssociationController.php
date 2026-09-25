<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}