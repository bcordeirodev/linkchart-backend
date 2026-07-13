<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request para criação de tags
 *
 * Centraliza todas as regras de validação para criação de tags de link,
 * seguindo o mesmo padrão de {@see \App\Http\Requests\CreateLinkRequest}
 * (mensagens customizadas + envelope de erro 422 padronizado).
 *
 * A cor é obrigatória e validada apenas quanto ao formato hexadecimal — o
 * frontend é responsável por atribuir valores de uma paleta fixa; o backend
 * não valida contra essa paleta, apenas o formato "#RRGGBB".
 */
class CreateTagRequest extends FormRequest
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
                'required',
                'string',
                'max:50',
            ],
            'color' => [
                'required',
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
            'name.required' => 'O nome da tag é obrigatório.',
            'name.max' => 'O nome da tag não pode ter mais de 50 caracteres.',
            'color.required' => 'A cor da tag é obrigatória.',
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
