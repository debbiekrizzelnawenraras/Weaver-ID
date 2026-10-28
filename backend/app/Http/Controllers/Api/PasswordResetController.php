<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Support\EmailCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Forgot password, for every role. Two steps: ask for a code, then type the code with a new
 * password. Nothing here says whether an email has an account; the answers are the same either way.
 */
class PasswordResetController extends Controller
{
    private const OFF = 'Password reset by email is switched off on this server. Ask DTI-Mountain Province to reset your password.';

    /** Same words for a wrong code, an expired code, a dead code and an email without an account. */
    private const BAD_CODE = 'That code is not correct or has expired. Ask for a new code.';

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
        ]);

        if (! EmailCodes::enabled()) {
            return response()->json(['message' => self::OFF], 503);
        }

        $user = $this->findActive($validated['email']);

        // Asked again within a minute: do nothing, and say nothing different.
        if ($user && EmailCodes::waitSeconds($user->email, EmailCodes::PASSWORD_RESET) === 0) {
            EmailCodes::send($user->email, EmailCodes::PASSWORD_RESET);
        }

        return response()->json([
            'message' => 'If an account uses this email address, a code has been sent to it. It works for '.EmailCodes::minutes().' minutes. Check your spam folder too.',
            'resend_in' => EmailCodes::resendSeconds(),
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'confirmed'],
        ], [
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'code.required' => 'Type the 6-digit code from your email.',
            'code.digits' => 'The code has 6 digits.',
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The password and its confirmation do not match.',
        ]);

        if (! EmailCodes::enabled()) {
            return response()->json(['message' => self::OFF], 503);
        }

        $user = $this->findActive($validated['email']);

        // The password rules first, so a password that is too weak does not use up a try on the code.
        $request->validate([
            'password' => [new StrongPassword($validated['email'], $user?->name)],
        ]);

        $result = $user ? EmailCodes::check($user->email, EmailCodes::PASSWORD_RESET, $validated['code']) : 'none';
        if ($result !== 'ok') {
            throw ValidationException::withMessages(['code' => self::BAD_CODE]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        // Whoever was signed in with the old password is signed out everywhere.
        $user->tokens()->delete();
        EmailCodes::forget($user->email, EmailCodes::PASSWORD_RESET);

        return response()->json(['message' => 'Password changed. You can sign in with the new password.']);
    }

    private function findActive(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [EmailCodes::normalise($email)])
            ->where('status', 'active')
            ->first();
    }
}
