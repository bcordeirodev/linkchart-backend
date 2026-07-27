<?php

namespace App\Http\Requests\Bio;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request for POST /api/bio/items (add a button to the authenticated
 * user's bio page).
 *
 * Only bounds the shape here — whether `link_id` belongs to the
 * authenticated user, whether their bio page exists yet, and the 20-item cap
 * are all business rules enforced by {@see \App\Services\Bio\BioPageService::addItem()}
 * and surfaced as 422 by the controller.
 */
class CreateBioPageItemRequest extends FormRequest
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
            'link_id' => ['required', 'integer'],
            'label' => ['nullable', 'string', 'max:255'],
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
