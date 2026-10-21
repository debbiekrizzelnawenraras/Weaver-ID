<?php

namespace App\Rules;

use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails once for every password rule that is not met, so the dashboard can
 * list exactly what to fix. Pass the account's name and email so a password
 * that contains them is refused.
 */
class StrongPassword implements ValidationRule
{
    public function __construct(
        private readonly ?string $email = null,
        private readonly ?string $name = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // the 'string' rule reports this
        }

        foreach (PasswordPolicy::failures($value, ['email' => $this->email, 'name' => $this->name]) as $rule) {
            $fail(PasswordPolicy::MESSAGES[$rule]);
        }
    }
}
