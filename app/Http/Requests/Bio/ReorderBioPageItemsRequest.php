<?php

namespace App\Http\Requests\Bio;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request for PUT /api/bio/items-order (reorder every button on the
 * authenticated user's bio page).
 *
 * Only bounds the shape here — whether `ids` matches exactly the set of item
 * ids currently on the page is a business rule enforced by
 * {@see \App\Services\Bio\BioPageService::reorderItems()}, surfaced as 422
 * by the controller.
 */
class ReorderBioPageItemsRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
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
