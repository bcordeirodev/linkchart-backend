<?php

namespace App\Http\Requests\Bio;

use App\Rules\BioHandleNotReserved;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form Request for PUT /api/bio (create-or-update the authenticated user's
 * bio page).
 *
 * `handle` is validated against the fixed format regex (3–30 chars,
 * lowercase alnum with internal hyphens only), the {@see BioHandleNotReserved}
 * blocklist, and global uniqueness — ignoring the authenticated user's own
 * existing row, so resubmitting an unchanged handle never trips the
 * uniqueness check against itself.
 *
 * `subdomain_id` only has its shape validated here (nullable integer).
 * Ownership and active-status are business rules enforced in
 * {@see \App\Services\Bio\BioPageService::upsert()}, mirroring how
 * {@see \App\Services\Links\LinkService::resolveShortDomain()} validates the
 * same relationship for links — surfaced as 422 by the controller. The field
 * is `sometimes`: absent from the payload leaves the current association
 * untouched; present as `null` explicitly removes it.
 */
class UpsertBioPageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->guard('api')->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'handle' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/',
                new BioHandleNotReserved,
                Rule::unique('bio_pages', 'handle')->ignore(auth()->guard('api')->id(), 'user_id'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:280'],
            'theme' => ['nullable', Rule::in(['dark', 'light'])],
            'is_active' => ['nullable', 'boolean'],
            'subdomain_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'handle.required' => 'O identificador é obrigatório.',
            'handle.regex' => 'O identificador deve ter 3-30 caracteres, apenas letras minúsculas, números e hífen interno.',
            'handle.unique' => 'Este identificador já está em uso.',
            'title.required' => 'O título é obrigatório.',
            'bio.max' => 'A bio não pode ter mais de 280 caracteres.',
            'theme.in' => 'O tema deve ser "dark" ou "light".',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * Normalizes `handle` to lowercase before the format regex and uniqueness
     * checks run, so "Ana" and "ana" are always treated as the same handle.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('handle')) {
            $this->merge(['handle' => strtolower(trim((string) $this->input('handle')))]);
        }
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'Dados de validação inválidos',
                'message' => 'Por favor, corrija os erros abaixo.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
