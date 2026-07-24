<?php

namespace Modules\Auth\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * min 12 chars + at least 3 of 4 classes (upper/lower/digit/symbol). MODULE_AUTH A-7.
 */
final class PasswordPolicy implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('PWD_POLICY');

            return;
        }

        if (mb_strlen($value) < 12) {
            $fail('PWD_POLICY');

            return;
        }

        $classes = 0;
        $classes += preg_match('/[A-Z]/', $value) ? 1 : 0;
        $classes += preg_match('/[a-z]/', $value) ? 1 : 0;
        $classes += preg_match('/[0-9]/', $value) ? 1 : 0;
        $classes += preg_match('/[^A-Za-z0-9]/', $value) ? 1 : 0;

        if ($classes < 3) {
            $fail('PWD_POLICY');
        }
    }
}
