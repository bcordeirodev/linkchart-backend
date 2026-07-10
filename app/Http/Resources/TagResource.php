<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API payload for a single Tag.
 *
 * Deliberately minimal — id, name, color — with no user_id or timestamps,
 * since tags are always presented within the context of the authenticated
 * user's own data (either standalone via GET /api/tags or embedded in
 * {@see \App\Http\Resources\LinkResource}).
 */
class TagResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
