<?php

namespace App\Support;

use App\Mail\EmailCodeMail;
use App\Models\EmailCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 6-digit codes emailed to confirm that an address belongs to the person typing it. Used when an
 * association applies and when someone forgets a password.
 *
 * A code works once, for a few minutes, and dies after a few wrong tries. Only a scrambled copy is
 * stored. Everything is switched off by WEAVER_EMAIL_CODES=off, for rehearsals without internet.
 */
class EmailCodes
{
    public const APPLICATION = 'application';

    public const PASSWORD_RESET = 'password_reset';

    public static function enabled(): bool
    {
        return (bool) config('weaver.email_codes.enabled');
    }

    public static function minutes(): int
    {
        return (int) config('weaver.email_codes.ttl_minutes');
    }

    public static function resendSeconds(): int
    {
        return (int) config('weaver.email_codes.resend_seconds');
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Seconds before another code may be emailed to this address for this purpose; 0 when one may go now. */
    public static function waitSeconds(string $email, string $purpose): int
    {
        $row = self::find($email, $purpose);
        if (! $row) {
            return 0;
        }

        return max(0, self::resendSeconds() - (now()->getTimestamp() - $row->sent_at->getTimestamp()));
    }

    /**
     * Make a new code for the address and email it. Returns false when the email could not be sent
     * (no internet, wrong mail settings); the code that was valid before is then left as it was.
     */
    public static function send(string $email, string $purpose): bool
    {
        $email = self::normalise($email);
        $previous = self::find($email, $purpose)?->only(['code_hash', 'attempts', 'expires_at', 'sent_at']);

        // Codes that ran out are of no use to anyone: clear them whenever a new one is made.
        EmailCode::where('expires_at', '<', now())->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailCode::updateOrCreate(
            ['email' => $email, 'purpose' => $purpose],
            [
                'code_hash' => self::scramble($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::minutes()),
                'sent_at' => now(),
            ],
        );

        try {
            Mail::to($email)->send(new EmailCodeMail($code, $purpose, self::minutes()));

            return true;
        } catch (Throwable $e) {
            // The log gets the reason (for the person setting up the mail account), never the code.
            Log::error('Weaver-ID could not send an email code: '.$e->getMessage());

            if ($previous) {
                EmailCode::where(['email' => $email, 'purpose' => $purpose])->update($previous);
            } else {
                EmailCode::where(['email' => $email, 'purpose' => $purpose])->delete();
            }

            return false;
        }
    }

    /**
     * Compare what was typed with the code on file. A wrong try is counted; the right one is not
     * used up here, so the caller can finish its other checks and then call forget().
     *
     * @return string ok, wrong, expired, locked or none
     */
    public static function check(string $email, string $purpose, string $typed): string
    {
        $row = self::find($email, $purpose);

        if (! $row) {
            return 'none';
        }
        if ($row->expires_at->isPast()) {
            return 'expired';
        }
        if ($row->attempts >= (int) config('weaver.email_codes.max_attempts')) {
            return 'locked';
        }
        if (! hash_equals($row->code_hash, self::scramble(trim($typed)))) {
            $row->increment('attempts');

            return $row->attempts >= (int) config('weaver.email_codes.max_attempts') ? 'locked' : 'wrong';
        }

        return 'ok';
    }

    /** The code has done its job. */
    public static function forget(string $email, string $purpose): void
    {
        EmailCode::where(['email' => self::normalise($email), 'purpose' => $purpose])->delete();
    }

    /** The sentence shown to the person for each answer of check(). */
    public static function message(string $result): string
    {
        return match ($result) {
            'none' => 'Press "Send code" first, then type the 6-digit code from your email.',
            'expired' => 'That code has expired. Send a new code.',
            'locked' => 'Too many wrong tries. Send a new code.',
            default => 'That code is not correct. Check the email and try again.',
        };
    }

    private static function find(string $email, string $purpose): ?EmailCode
    {
        return EmailCode::where(['email' => self::normalise($email), 'purpose' => $purpose])->first();
    }

    /** A keyed hash: the stored value is useless without this server's key. */
    private static function scramble(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
