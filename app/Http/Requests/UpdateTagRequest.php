<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request para atualização de tags
 *
 * Centraliza todas as regras de validação para atualização de tags de link,
 * seguindo o mesmo padrão de {@see \App\Http\Requests\UpdateLinkRequest}.
 * Ambos os campos são opcionais (`sometimes`) para permitir atualização
 * parcial, mas quando enviados seguem as mesmas regras de formato do
 * {@see \App\Http\Requests\CreateTagRequest}.
 */
class UpdateTagRequest extends FormRequest
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
            'name' => [
                'sometimes',
                'string',
                'max:50',
            ],
            'color' => [
                'sometimes',
                'string',
                'regex:/^#[0-9a-fA-F]{6}$/',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.max' => 'O nome da tag não pode ter mais de 50 caracteres.',
            'color.regex' => 'A cor deve ser um código hexadecimal válido (ex: #3B82F6).',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'color' => 'cor',
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
}
