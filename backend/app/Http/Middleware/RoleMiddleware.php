<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        ...$roles
    ): Response {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // A deactivated account loses access immediately, even with a valid token.
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'This account is inactive.',
            ], 403);
        }

        if (!in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        return $next($request);
    }
}