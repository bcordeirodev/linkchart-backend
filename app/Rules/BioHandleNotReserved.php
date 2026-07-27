<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects bio handles present in `config('bio.reserved_handles')`.
 *
 * Runs alongside the handle's format regex in
 * {@see \App\Http\Requests\Bio\UpsertBioPageRequest}. Matching is
 * case-insensitive, though in practice the value reaching this rule is
 * already lowercased by the request's `prepareForValidation()`.
 */
class BioHandleNotReserved implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $reserved = config('bio.reserved_handles', []);

        if (in_array(strtolower((string) $value), $reserved, true)) {
            $fail('Este identificador é reservado e não pode ser usado.');
        }
    }
}
