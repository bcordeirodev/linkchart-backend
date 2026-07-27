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
 * `subdomain_id` only has its shape validated here (nullable integer). Every
 * other rule around it — ownership/active-status, AND the product rule that a
 * bio page's subdomain is now mandatory (a page can no longer be created, nor
 * knowingly re-saved, without one; product decision recorded 2026-07-27) — is
 * a business rule enforced in {@see \App\Services\Bio\BioPageService::upsert()}
 * (ownership mirrors how {@see \App\Services\Links\LinkService::resolveShortDomain()}
 * validates the same relationship for links), surfaced as 422 by the
 * controller. The field is `sometimes` at THIS layer only: absent from the
 * payload is shape-valid here and may still be rejected downstream (when
 * creating, or updating a legacy page that has no association yet); present
 * as `null` is also shape-valid here and always rejected downstream now that
 * detaching a subdomain via update is no longer allowed.
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
            // 'sometimes': o editor nao envia handle (subdomain-first) — na
            // criacao o service deriva do label do subdominio; no update,
            // ausente mantem o atual. Explicito continua validado (API).
            'handle' => [
                'sometimes',
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
