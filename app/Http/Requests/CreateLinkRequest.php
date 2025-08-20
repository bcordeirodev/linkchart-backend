<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request para criação de links
 *
 * Centraliza todas as regras de validação para criação de links,
 * seguindo o princípio DRY e SRP.
 */
class CreateLinkRequest extends FormRequest
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
                'required',
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
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before:' . now()->addYears(5)->toDateString(), // Máximo 5 anos
            ],
            'starts_in' => [
                'nullable',
                'date',
                'after_or_equal:now',
                'before:expires_at', // Deve começar antes de expirar
            ],
            'click_limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000000', // Máximo 1 milhão de cliques
            ],
            'custom_slug' => [
                'nullable',
                'string',
                'min:3',
                'max:50',
                'alpha_dash',
                'unique:links,slug',
                'not_in:api,admin,www,mail,ftp', // Slugs reservados
            ],
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'original_url.required' => 'A URL original é obrigatória.',
            'original_url.url' => 'A URL deve ser válida.',
            'original_url.max' => 'A URL não pode ter mais de 2048 caracteres.',
            'original_url.regex' => 'A URL deve começar com http:// ou https://.',

            'expires_at.date' => 'A data de expiração deve ser uma data válida.',
            'expires_at.after' => 'A data de expiração deve ser no futuro.',
            'expires_at.before' => 'A data de expiração não pode ser superior a 5 anos.',

            'starts_in.date' => 'A data de início deve ser uma data válida.',
            'starts_in.after_or_equal' => 'A data de início deve ser no presente ou futuro.',
            'starts_in.before' => 'A data de início deve ser anterior à data de expiração.',

            'custom_slug.min' => 'O slug personalizado deve ter pelo menos 3 caracteres.',
            'custom_slug.max' => 'O slug personalizado não pode ter mais de 50 caracteres.',
            'custom_slug.alpha_dash' => 'O slug pode conter apenas letras, números, hífens e underscores.',
            'custom_slug.unique' => 'Este slug já está em uso.',
            'custom_slug.not_in' => 'Este slug é reservado e não pode ser usado.',
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
            'custom_slug' => 'slug personalizado',
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
        // Limpa e normaliza a URL
        if ($this->has('original_url')) {
            $url = trim($this->input('original_url'));

            // Adiciona https:// se não tiver protocolo
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'https://' . $url;
            }

            $this->merge(['original_url' => $url]);
        }

        // Normaliza o slug personalizado
        if ($this->has('custom_slug')) {
            $slug = strtolower(trim($this->input('custom_slug')));
            $this->merge(['custom_slug' => $slug]);
        }
    }
}
