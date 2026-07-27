<?php

namespace App\Http\Requests\Bio;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Dimensions;

/**
 * Form Request for POST /api/bio/avatar (upload/replace the authenticated
 * user's bio page avatar).
 *
 * Only bounds the shape of the uploaded file here — whether the user already
 * has a bio page to attach the avatar to is a business rule enforced by
 * {@see \App\Services\Bio\BioPageService::uploadAvatar()} and surfaced as 422
 * by the controller, mirroring how {@see CreateBioPageItemRequest} defers
 * `link_id` ownership to the service layer.
 *
 * No image-processing library is used (no Intervention Image, etc.) — the
 * file is stored exactly as uploaded once these rules pass. Resize/optimize
 * is an explicitly deferred follow-up.
 */
class UploadBioAvatarRequest extends FormRequest
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
     *
     * `mimes:jpeg,png,webp` alone already rejects non-image files (a PDF or
     * text file has no matching extension/mime), but `image` is kept
     * alongside it as a second, independent guard — it additionally verifies
     * Laravel can actually decode the upload as an image, not just that its
     * extension/mime claims to be one.
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                'max:2048',
                (new Dimensions)->minWidth(100)->minHeight(100),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'A imagem do avatar é obrigatória.',
            'avatar.image' => 'O arquivo enviado precisa ser uma imagem.',
            'avatar.mimes' => 'A imagem deve ser JPEG, PNG ou WEBP.',
            'avatar.max' => 'A imagem não pode passar de 2MB.',
            'avatar.dimensions' => 'A imagem precisa ter pelo menos 100x100 pixels.',
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
