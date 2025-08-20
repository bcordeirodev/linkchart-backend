<?php

namespace App\DTOs;

use App\Models\Link;
use Illuminate\Http\Request;

class LinkDTO
{
    public ?string $id;
    public string $original_url;
    public string $expires_at;
    public bool $is_active;
    public string $created_at;
    public string $updated_at;
    public string $starts_in;

    /**
     * Construtor do DTO.
     * Se $id for null, significa que é uma criação, caso contrário, é uma saída.
     */
    public function __construct(
        ?string $id,
        string $original_url,
        string $expires_at,
        bool $is_active,
        string $created_at,
        string $updated_at,
        string $starts_in
    ) {
        $this->id = $id;
        $this->original_url = $original_url;
        $this->expires_at = $expires_at;
        $this->is_active = $is_active;
        $this->created_at = $created_at;
        $this->updated_at = $updated_at;
        $this->starts_in = $starts_in;
    }

    /**
     * Cria uma instância do DTO a partir da Request.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->input('id'),
            $request->input('original_url'),
            $request->input('expires_at'),
            filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
            $request->input('created_at'),
            $request->input('updated_at'),
            $request->input('starts_in')
        );
    }

    /**
     * Cria uma instância do DTO a partir do model Link.
     */
    public static function fromModel(Link $link): self
    {
        return new self(
            $link->id,
            $link->original_url,
            $link->expires_at,
            $link->is_active,
            $link->created_at,
            $link->updated_at,
            $link->starts_in
        );
    }

    /**
     * Converte o DTO para um array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'original_url' => $this->original_url,
            'expires_at' => $this->expires_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'starts_in' => $this->starts_in,
        ];
    }
}
