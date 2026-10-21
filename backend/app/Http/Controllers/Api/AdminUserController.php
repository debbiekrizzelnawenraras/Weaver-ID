<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::with('association')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', new StrongPassword($request->input('email'), $request->input('name'))],
            'role' => ['required', Rule::in(['admin', 'staff', 'association'])],
            'municipality' => [Rule::requiredIf($request->input('role') === 'staff'), 'nullable', 'string', 'max:255'],
            'association_id' => [
                Rule::requiredIf($request->input('role') === 'association'),
                'nullable',
                'integer',
                'exists:associations,id',
            ],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            // The administrator chose this password, so the person must replace it at first login.
            'must_change_password' => true,
            'role' => $validated['role'],
            'municipality' => $validated['role'] === 'staff' ? $validated['municipality'] : null,
            'association_id' => $validated['role'] === 'association' ? $validated['association_id'] : null,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user->load('association'),
        ], 201);
    }

    /**
     * Edit a user. The role cannot be changed; create a new account instead.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', new StrongPassword($request->input('email', $user->email), $request->input('name', $user->name))],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'municipality' => [Rule::requiredIf($user->role === 'staff'), 'nullable', 'string', 'max:255'],
            'association_id' => [
                Rule::requiredIf($user->role === 'association'),
                'nullable',
                'integer',
                'exists:associations,id',
            ],
        ]);

        if ($user->is($request->user()) && $validated['status'] === 'inactive') {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $changes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => $validated['status'],
            'municipality' => $user->role === 'staff' ? $validated['municipality'] : null,
            'association_id' => $user->role === 'association' ? $validated['association_id'] : null,
        ];

        if (! empty($validated['password'])) {
            $changes['password'] = $validated['password'];
            // A reset password is known to the administrator, unless they reset their own.
            $changes['must_change_password'] = ! $user->is($request->user());
        }

        $user->update($changes);

        // Deactivation or a password reset signs the person out everywhere.
        if ($validated['status'] === 'inactive' || ! empty($validated['password'])) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user->fresh()->load('association'),
        ]);
    }
}
