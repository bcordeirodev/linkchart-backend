<?php

namespace App\Http\Requests\Bio;

use App\Models\BioPageItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form Request for PUT /api/bio/items/{id} (partial update of a bio page button).
 *
 * All fields are optional — the caller sends only what changed. Ownership
 * (the item's bio page must belong to the authenticated user) is enforced by
 * {@see \App\Services\Bio\BioPageService::updateItem()}, surfaced as 404 by
 * the controller.
 *
 * `display` is editable, same as `label`/`is_active` — product decision
 * (2026-08-04): the simplest coherent rule is uniform editability across
 * every mutable item field, rather than special-casing `display` as
 * immutable. Switching an item back to 'item' always clears
 * `social_platform` server-side (see BioPageService::updateItem()), so the
 * invariant "social_platform is non-null only when display=icon" holds
 * regardless of which field the caller touches.
 *
 * `social_platform` is deliberately NOT `sometimes` — `required_if` must
 * fire even when the client omits the key while also sending
 * `display: icon`; `sometimes` would skip that check entirely. When
 * `display` is absent from THIS request (the common case — e.g. only
 * `label` changed), `required_if`'s condition is false and
 * `social_platform` stays fully optional here, whatever the item's stored
 * `display` already is.
 */
class UpdateBioPageItemRequest extends FormRequest
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
            'label' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'display' => ['sometimes', 'string', Rule::in([BioPageItem::DISPLAY_ITEM, BioPageItem::DISPLAY_ICON])],
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
