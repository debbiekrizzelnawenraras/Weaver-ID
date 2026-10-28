<?php

// The pages that use email codes, checked as files: the right fields are there, the old "no emails"
// sentence is gone, and no mail password is in the example settings. What the pages do is checked in a
// browser; the server rules are in EmailCodeApplicationTest and PasswordResetTest.

/** The text of a file under public/ or the backend folder. */
function pageText(string $path, bool $public = true): string
{
    $full = $public ? public_path($path) : base_path($path);
    expect(is_file($full))->toBeTrue("{$path} is missing");

    return (string) file_get_contents($full);
}

it('asks for the code on the application page and no longer says no emails are sent', function () {
    $html = pageText('register.html');

    expect($html)->toContain('id="sendCode"')->toContain('id="email_code"')->toContain('autocomplete="one-time-code"');
    expect($html)->not->toContain('does not send emails');
    expect($html)->toContain('6-digit code')->toContain('the result of your application is not sent to you');
});

it('sends the code request and the typed code from the application page', function () {
    $js = pageText('js/register.js');

    expect($js)->toContain('/api/email-codes')->toContain('email_code');
});

it('offers forgot password from the sign-in page', function () {
    expect(pageText('login.html'))->toContain('forgot-password.html');
});

it('has a forgot-password page with the code, the new password and the strength meter', function () {
    $html = pageText('forgot-password.html');
    $js = pageText('js/forgot-password.js');

    expect($html)->toContain('id="askForm"')->toContain('id="resetForm"')->toContain('id="code"')
        ->toContain('id="nameHint"')
        ->toContain('js/password-policy.js')->toContain('js/forgot-password.js');
    expect($js)->toContain('/api/password/forgot')->toContain('/api/password/reset');
});

it('shows reviewers whether the applicant confirmed the email', function () {
    $js = pageText('js/admin.js');

    expect($js)->toContain('Email confirmed')->toContain('Email not confirmed');
    expect($js)->not->toContain('sends no email');
});

it('has a newer privacy notice version than the one without email codes', function () {
    expect(config('weaver.application_notice_version'))->not->toBe('2026-10');
});

it('keeps a mail password out of the example settings', function () {
    $env = pageText('.env.example', false);

    expect($env)->toMatch('/^MAIL_PASSWORD=(null)?$/m');
    expect($env)->toMatch('/^WEAVER_EMAIL_CODES=on$/m');
});
