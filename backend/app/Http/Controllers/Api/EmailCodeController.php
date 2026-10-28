<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\EmailCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public: emails a 6-digit code to an address that is about to be used in an association application.
 * The answer is the same whether or not the address already has an account or an application, so
 * this endpoint cannot be used to find out who is registered; the application itself says so, but
 * only to someone who proved the mailbox is theirs.
 */
class EmailCodeController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.required' => 'Enter an email address.',
            'email.email' => 'Enter a valid email address.',
        ]);

        if (! EmailCodes::enabled()) {
            return response()->json(['message' => 'Email codes are switched off on this server.'], 503);
        }

        $wait = EmailCodes::waitSeconds($validated['email'], EmailCodes::APPLICATION);
        if ($wait > 0) {
            return response()->json([
                'message' => "A code was sent a moment ago. You can ask for another in {$wait} seconds.",
                'retry_after' => $wait,
            ], 429)->header('Retry-After', (string) $wait);
        }

        if (! EmailCodes::send($validated['email'], EmailCodes::APPLICATION)) {
            return response()->json([
                'message' => 'The code could not be sent. Check the internet connection of the server and try again.',
            ], 503);
        }

        return response()->json([
            'message' => 'Code sent. It works for '.EmailCodes::minutes().' minutes. Check your inbox and your spam folder.',
            'expires_in' => EmailCodes::minutes() * 60,
            'resend_in' => EmailCodes::resendSeconds(),
        ]);
    }
}
