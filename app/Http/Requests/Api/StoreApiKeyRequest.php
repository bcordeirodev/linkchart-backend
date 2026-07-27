<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para criação de uma API key (POST /api/api-keys).
 *
 * Valida apenas o nome da chave; o limite de 5 chaves por usuário é regra de
 * negócio verificada no controller (depende do estado atual do banco, não do
 * payload). Rota protegida por api.auth:api + verified — o token Sanctum
 * emitido aqui NUNCA autentica nas rotas de gestão (isolamento de guards).
 */
class StoreApiKeyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * A rota já exige o guard JWT do painel (api.auth:api); aqui só se confirma
     * que há usuário resolvido nesse guard.
     */
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
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
            'name.required' => 'O nome da chave é obrigatório.',
            'name.max' => 'O nome da chave não pode ter mais de 60 caracteres.',
        ];
    }
}
