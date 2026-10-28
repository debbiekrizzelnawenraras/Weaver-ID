Your Weaver-ID code is {{ $code }}

@if ($purpose === 'password_reset')
Type it on the "Forgot password" page to choose a new password.
@else
Type it on the application page to confirm that this email address is yours.
@endif

The code works once and for {{ $minutes }} minutes. No one from DTI-Mountain Province will ask you for it.

If you did not ask for this code, ignore this email. Nothing changes.
