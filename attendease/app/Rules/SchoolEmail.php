<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/** FR-AUTH-02: the email must end in @<ALLOWED_EMAIL_DOMAIN> (trimmed, case-insensitive). */
class SchoolEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = Str::lower(trim((string) config('app.allowed_email_domain')));
        $email = Str::lower(trim((string) $value));

        if (! Str::endsWith($email, '@'.$domain) || strlen($email) <= strlen($domain) + 1) {
            $fail("Use your school email address (ending in @{$domain}).");
        }
    }
}
