<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request para criação de links públicos
 *
 * FUNCIONALIDADE:
 * - Validação para links criados sem autenticação
 * - Regras de segurança mais restritivas
 * - Sem campos avançados (apenas URL básica)
 * - Rate limiting aplicado via middleware
 */
class CreatePublicLinkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Para links públicos, sempre autorizado (rate limiting via middleware).
     */
    public function authorize(): bool
    {
        return true;
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
                    // Validação mais rigorosa para URLs públicas
                    $blockedDomains = [
                        'malware.com', 'phishing.net', 'spam.org',
                        'localhost', '127.0.0.1', '192.168.',
                        'file://', 'ftp://', 'data:'
                    ];

                    $domain = parse_url($value, PHP_URL_HOST);
                    $fullUrl = strtolower($value);

                    // Bloquear domínios maliciosos
                    foreach ($blockedDomains as $blocked) {
                        if (strpos($fullUrl, $blocked) !== false) {
                            $fail('Esta URL não é permitida por questões de segurança.');
                            return;
                        }
                    }

                    // Bloquear IPs privados
                    if (filter_var($domain, FILTER_VALIDATE_IP)) {
                        if (!filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                            $fail('URLs com IPs privados não são permitidas.');
                            return;
                        }
                    }
                }
            ],
            'title' => [
                'nullable',
                'string',
                'max:100', // Mais restritivo para links públicos
            ],
            'custom_slug' => [
                'nullable',
                'string',
                'min:3',
                'max:20', // Mais restritivo para links públicos
                'alpha_dash',
                'unique:links,slug',
                'not_in:api,admin,www,mail,ftp,app,web,public,short,link,url', // Mais slugs reservados
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'original_url.required' => 'A URL é obrigatória.',
            'original_url.url' => 'A URL deve ser válida.',
            'original_url.max' => 'A URL não pode ter mais de 2048 caracteres.',
            'original_url.regex' => 'A URL deve começar com http:// ou https://.',

            'title.max' => 'O título não pode ter mais de 100 caracteres.',

            'custom_slug.min' => 'O slug personalizado deve ter pelo menos 3 caracteres.',
            'custom_slug.max' => 'O slug personalizado não pode ter mais de 20 caracteres.',
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
            'original_url' => 'URL',
            'title' => 'título',
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

        // Limpa o título
        if ($this->has('title')) {
            $title = trim($this->input('title'));
            $this->merge(['title' => $title]);
        }
    }
}
