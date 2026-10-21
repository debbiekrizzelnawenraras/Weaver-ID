<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account whose password an administrator chose may only read its own
 * details, change its password, or log out until it has set its own. Checked
 * on the server so the dashboard cannot be skipped by calling the API directly.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->is('api/user', 'api/change-password', 'api/logout')) {
            return response()->json([
                'message' => 'You must change your password before you continue.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
