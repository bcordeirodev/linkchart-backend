<?php

namespace App\Http\Requests\Bio;

use App\Models\BioPageItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form Request for POST /api/bio/items (add a button to the authenticated
 * user's bio page).
 *
 * Only bounds the shape here — whether `link_id` belongs to the
 * authenticated user, whether their bio page exists yet, and the 20-item cap
 * are all business rules enforced by {@see \App\Services\Bio\BioPageService::addItem()}
 * and surfaced as 422 by the controller. `link_id` is required regardless of
 * `display` — an icon item is still a link item (tracking preserved), just
 * rendered differently.
 *
 * `social_platform` is `required_if:display,icon` (not `sometimes`) so the
 * rule fires even when the client omits the key entirely — Laravel's
 * `sometimes` modifier would otherwise skip `required_if` altogether when
 * the field is absent from the payload, defeating the whole point of making
 * it conditionally required.
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
            'display' => ['nullable', 'string', Rule::in([BioPageItem::DISPLAY_ITEM, BioPageItem::DISPLAY_ICON])],
            'social_platform' => [
                'nullable', 'string',
                'required_if:display,'.BioPageItem::DISPLAY_ICON,
                Rule::in(config('bio.social_platforms', [])),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'display.in' => 'O tipo de exibição deve ser "item" ou "icon".',
            'social_platform.required_if' => 'Selecione a plataforma do ícone social.',
            'social_platform.in' => 'Plataforma de ícone social não suportada.',
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
