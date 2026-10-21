<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'role' => ['sometimes', 'in:admin,staff,association'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (
            !$user ||
            !Hash::check($credentials['password'], $user->password)
        ) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'This account is inactive.',
            ], 403);
        }

        if (isset($credentials['role']) && $user->role !== $credentials['role']) {
            return response()->json([
                'message' => 'The selected role does not match this account.',
            ], 403);
        }

        $token = $user->createToken('weaver-id')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'association_id' => $user->association_id,
                'must_change_password' => (bool) $user->must_change_password,
            ],
        ]);
    }

    /**
     * The signed-in person sets their own password. The current one is checked
     * first, then the new one against the password rules. Every other session
     * of this account is signed out; this one stays.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        // 422, not 401, so the dashboard shows the message instead of signing the person out.
        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is not correct.',
            ]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', new StrongPassword($user->email, $user->name)],
        ]);

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The new password must be different from the current one.',
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        $current = $user->currentAccessToken();
        $keep = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        $user->tokens()->when($keep, fn ($tokens) => $tokens->where('id', '!=', $keep))->delete();

        return response()->json([
            'message' => 'Password changed. Other devices were signed out.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'association_id' => $user->association_id,
                'must_change_password' => false,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}