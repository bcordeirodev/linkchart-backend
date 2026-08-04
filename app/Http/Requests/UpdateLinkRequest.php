<?php

namespace App\Http\Requests;

use App\Services\Links\LinkSafetyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form Request para atualização de links
 *
 * Centraliza todas as regras de validação para atualização de links,
 * seguindo o princípio DRY e SRP.
 */
class UpdateLinkRequest extends FormRequest
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
            'original_url' => [
                'sometimes',
                'url',
                'max:4096',
                'regex:/^https?:\/\//',
            ],
            'title' => 'sometimes|string|max:100',
            // Wire field is `custom_slug` (matches CreateLinkRequest and the
            // frontend form field) — the DB column it maps to is `slug`
            // (see UpdateLinkDTO). `unique` ignores this link's own id so
            // saving the link unchanged, or re-picking its current slug,
            // never collides with itself; the DB index is a single global
            // unique constraint on `slug` (no per-domain/subdomain scoping
            // exists today), so no extra `where` is needed here.
            'custom_slug' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\-_]+$/',
                Rule::unique('links', 'slug')->ignore($this->route('id')),
            ],
            'description' => 'sometimes|string|max:500',
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before:'.now()->addYears(5)->toDateString(),
            ],
            'starts_in' => [
                'nullable',
                'date',
                'after_or_equal:now',
                function ($attribute, $value, $fail) {
                    if ($value && $this->input('expires_at')) {
                        $startsIn = new \DateTime($value);
                        $expiresAt = new \DateTime($this->input('expires_at'));

                        if ($startsIn >= $expiresAt) {
                            $fail('A data de início deve ser anterior à data de expiração.');
                        }
                    }
                },
            ],
            'is_active' => 'sometimes|boolean',
            'click_limit' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:1000000', // Máximo 1 milhão de cliques
            ],
            // Senha do link (write-only). Semântica de update: presente com
            // valor => define/troca (hash em LinkService); presente como
            // null/vazio => remove; ausente => não mexe. Máx. 72 pelo limite
            // de input do bcrypt.
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:4',
                'max:72',
            ],
            'utm_source' => 'sometimes|nullable|string|max:100',
            'utm_medium' => 'sometimes|nullable|string|max:100',
            'utm_campaign' => 'sometimes|nullable|string|max:100',
            'utm_term' => 'sometimes|nullable|string|max:100',
            'utm_content' => 'sometimes|nullable|string|max:100',

            // Tags — ownership of each id is verified (and silently filtered)
            // in LinkService, not here; this only bounds the shape/size. When
            // present, tag_ids fully replaces the link's tag set (sync, not merge).
            'tag_ids' => 'sometimes|nullable|array|max:5',
            'tag_ids.*' => 'integer',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'original_url.url' => 'A URL deve ser válida.',
            'original_url.max' => 'A URL não pode ter mais de 4096 caracteres.',
            'original_url.regex' => 'A URL deve começar com http:// ou https://.',

            'expires_at.date' => 'A data de expiração deve ser uma data válida.',
            'expires_at.after' => 'A data de expiração deve ser no futuro.',
            'expires_at.before' => 'A data de expiração não pode ser superior a 5 anos.',

            'starts_in.date' => 'A data de início deve ser uma data válida.',
            'starts_in.after_or_equal' => 'A data de início deve ser no presente ou futuro.',
            'starts_in.before' => 'A data de início deve ser anterior à data de expiração.',

            'tag_ids.array' => 'As tags devem ser enviadas como uma lista.',
            'tag_ids.max' => 'Um link pode ter no máximo 5 tags.',
            'tag_ids.*.integer' => 'Cada tag deve ser um identificador numérico válido.',

            'password.min' => 'A senha do link deve ter pelo menos 4 caracteres.',
            'password.max' => 'A senha do link não pode ter mais de 72 caracteres.',

            'custom_slug.max' => 'O slug personalizado não pode ter mais de 100 caracteres.',
            'custom_slug.regex' => 'O slug pode conter apenas letras, números, hífens e underscores.',
            'custom_slug.unique' => 'Este slug já está em uso.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'original_url' => 'URL original',
            'expires_at' => 'data de expiração',
            'starts_in' => 'data de início',
            'is_active' => 'status ativo',
            'custom_slug' => 'slug personalizado',
        ];
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('original_url') || ! $this->has('original_url')) {
                return;
            }

            $url = $this->input('original_url');
            if (! $url) {
                return;
            }

            $result = app(LinkSafetyService::class)->checkUrl($url);

            if (! $result['safe']) {
                $threats = implode(', ', $result['threats']);
                $validator->errors()->add(
                    'original_url',
                    "Esta URL foi identificada como insegura ({$threats}) e não pode ser encurtada."
                );
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Limpa e normaliza a URL se fornecida
        if ($this->has('original_url')) {
            $url = trim($this->input('original_url'));

            // Adiciona https:// se não tiver protocolo
            if (! preg_match('/^https?:\/\//', $url)) {
                $url = 'https://'.$url;
            }

            $this->merge(['original_url' => $url]);
        }

        // Normaliza o slug personalizado — mesma regra do CreateLinkRequest,
        // para que a comparação de unicidade e o valor persistido sejam
        // sempre lowercase/trim independentemente de como o cliente enviou.
        if ($this->has('custom_slug')) {
            $slug = strtolower(trim((string) $this->input('custom_slug')));
            $this->merge(['custom_slug' => $slug]);
        }
    }

    /**
     * Verifica se há dados para atualizar.
     *
     * `tag_ids` is included here even though it is excluded from
     * {@see \App\DTOs\UpdateLinkDTO::toArray()} mass-assignment (tags are a
     * relation, synced separately) — otherwise a request that sends only
     * `tag_ids` would be rejected as "no data to update" before ever
     * reaching {@see \App\Services\Links\LinkService::updateLink()}.
     * `password` is included for the same reason: it never goes through
     * mass-assignment (LinkService hashes it into `password_hash` explicitly),
     * but a request that only sets/removes the password is a valid update.
     */
    public function hasDataToUpdate(): bool
    {
        $updateableFields = [
            'original_url', 'title', 'custom_slug', 'description', 'expires_at',
            'starts_in', 'is_active', 'utm_source', 'utm_medium',
            'utm_campaign', 'utm_term', 'utm_content', 'tag_ids', 'password',
        ];

        foreach ($updateableFields as $field) {
            if ($this->has($field)) {
                return true;
            }
        }

        return false;
    }
}
