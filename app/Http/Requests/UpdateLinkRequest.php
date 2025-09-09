<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
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
                'max:2048',
                'regex:/^https?:\/\//',
                function ($attribute, $value, $fail) {
                    // Validação customizada para URLs maliciosas
                    $blockedDomains = ['malware.com', 'phishing.net', 'spam.org'];
                    $domain = parse_url($value, PHP_URL_HOST);

                    if (in_array($domain, $blockedDomains)) {
                        $fail('Esta URL não é permitida por questões de segurança.');
                    }
                }
            ],
            'title' => 'sometimes|string|max:100',
            'slug' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9\-_]+$/',
            ],
            'description' => 'sometimes|string|max:500',
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before:' . now()->addYears(5)->toDateString(),
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
            'utm_source' => 'sometimes|nullable|string|max:100',
            'utm_medium' => 'sometimes|nullable|string|max:100',
            'utm_campaign' => 'sometimes|nullable|string|max:100',
            'utm_term' => 'sometimes|nullable|string|max:100',
            'utm_content' => 'sometimes|nullable|string|max:100',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'original_url.url' => 'A URL deve ser válida.',
            'original_url.max' => 'A URL não pode ter mais de 2048 caracteres.',
            'original_url.regex' => 'A URL deve começar com http:// ou https://.',

            'expires_at.date' => 'A data de expiração deve ser uma data válida.',
            'expires_at.after' => 'A data de expiração deve ser no futuro.',
            'expires_at.before' => 'A data de expiração não pode ser superior a 5 anos.',

            'starts_in.date' => 'A data de início deve ser uma data válida.',
            'starts_in.after_or_equal' => 'A data de início deve ser no presente ou futuro.',
            'starts_in.before' => 'A data de início deve ser anterior à data de expiração.',
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
                'errors' => $validator->errors()
            ], 422)
        );
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
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'https://' . $url;
            }

            $this->merge(['original_url' => $url]);
        }
    }

    /**
     * Verifica se há dados para atualizar.
     */
    public function hasDataToUpdate(): bool
    {
        $updateableFields = [
            'original_url', 'title', 'slug', 'description', 'expires_at',
            'starts_in', 'is_active', 'utm_source', 'utm_medium',
            'utm_campaign', 'utm_term', 'utm_content'
        ];

        foreach ($updateableFields as $field) {
            if ($this->has($field)) {
                return true;
            }
        }

        return false;
    }
}
