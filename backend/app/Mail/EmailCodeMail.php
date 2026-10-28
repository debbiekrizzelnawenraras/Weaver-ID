<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The only email Weaver-ID sends: a 6-digit code, as plain text. */
class EmailCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === 'password_reset'
                ? 'Weaver-ID: your password reset code'
                : 'Weaver-ID: your email confirmation code',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.email-code');
    }
}
