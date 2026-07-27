<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Links\LinkSafetyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para criação de link pela API pública (POST /api/v1/links).
 *
 * Espelha as regras de {@see \App\Http\Requests\CreateLinkRequest} (painel)
 * para o subconjunto de campos expostos no contrato v1 — original_url, slug,
 * title, expires_at, click_limit, subdomain_id e utm_* — com duas diferenças
 * deliberadas:
 *
 *   - o campo público chama-se `slug` (não `custom_slug`);
 *   - a validação falha com a ValidationException padrão, renderizada pelo
 *     handler global como {error: {code: VALIDATION_FAILED, ...}} (422).
 *
 * A verificação de segurança da URL (heurística local + Google Safe Browsing,
 * via {@see LinkSafetyService}) roda em withValidator exatamente como no
 * painel — a API pública NÃO bypassa as camadas anti-phishing.
 */
class StoreLinkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * A rota já exige auth:sanctum; aqui só se confirma que o token resolveu
     * um usuário.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'original_url' => [
                'required',
                'url',
                'max:4096',
                'regex:/^https?:\/\//',
            ],
            'slug' => [
                'nullable',
                'string',
                'min:3',
                'max:100',
                'alpha_dash',
                'unique:links,slug',
                'not_in:api,admin,www,mail,ftp', // Slugs reservados
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before:'.now()->addYears(5)->toDateString(), // Máximo 5 anos
            ],
            'click_limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000000',
            ],

            // Ownership e status ativo são validados no LinkService
            // (resolveShortDomain), igual ao painel — ver
            // CreateLinkRequest::rules() e CreateLinkDTO::$subdomain_id_provided.
            'subdomain_id' => [
                'nullable',
                'integer',
            ],

            // UTM Parameters
            'utm_source' => 'nullable|string|max:100',
            'utm_medium' => 'nullable|string|max:100',
            'utm_campaign' => 'nullable|string|max:100',
            'utm_term' => 'nullable|string|max:100',
            'utm_content' => 'nullable|string|max:100',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'original_url.required' => 'A URL original é obrigatória.',
            'original_url.url' => 'A URL deve ser válida.',
            'original_url.max' => 'A URL não pode ter mais de 4096 caracteres.',
            'original_url.regex' => 'A URL deve começar com http:// ou https://.',

            'slug.min' => 'O slug deve ter pelo menos 3 caracteres.',
            'slug.max' => 'O slug não pode ter mais de 100 caracteres.',
            'slug.alpha_dash' => 'O slug pode conter apenas letras, números, hífens e underscores.',
            'slug.unique' => 'Este slug já está em uso.',
            'slug.not_in' => 'Este slug é reservado e não pode ser usado.',

            'expires_at.date' => 'A data de expiração deve ser uma data válida.',
            'expires_at.after' => 'A data de expiração deve ser no futuro.',
            'expires_at.before' => 'A data de expiração não pode ser superior a 5 anos.',

            'subdomain_id.integer' => 'O subdomain_id deve ser um número inteiro.',
        ];
    }

    /**
     * Roda a checagem de segurança da URL após as regras declarativas.
     *
     * Mesmo pipeline do painel: heurística local de marca/keyword primeiro,
     * depois Google Safe Browsing (fail-open sem chave configurada). URL
     * reprovada vira erro de validação em `original_url` → 422.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('original_url')) {
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
     *
     * Normaliza a URL (trim + https:// default) e o slug (lowercase), como no
     * fluxo do painel, para que as regras declarativas validem o valor final.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('original_url')) {
            $url = trim((string) $this->input('original_url'));

            if ($url !== '' && ! preg_match('/^https?:\/\//', $url)) {
                $url = 'https://'.$url;
            }

            $this->merge(['original_url' => $url]);
        }

        if ($this->has('slug')) {
            $this->merge(['slug' => strtolower(trim((string) $this->input('slug')))]);
        }
    }
}
