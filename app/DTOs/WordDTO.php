<?php

namespace App\DTOs;

use App\Models\Word;
use Illuminate\Http\Request;

class WordDTO
{
    public ?string $id;
    public string $word;
    public string $response;
    public int $rating;

    /**
     * Construtor do DTO.
     * Se $id for null, significa que é uma criação, caso contrário, é uma saída.
     */
    public function __construct(?string $id, string $word, string $response, int $rating)
    {
        $this->id       = $id;
        $this->word     = $word;
        $this->response = $response;
        $this->rating   = $rating;
    }

    /**
     * Cria uma instância do DTO a partir da Request.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->input('id') ? $request->input('word') : null,
            $request->input('word'),
            $request->input('response'),
            (int) $request->input('rating')
        );
    }

    /**
     * Cria uma instância do DTO a partir do model Word.
     */
    public static function fromModel(Word $word): self
    {
        return new self(
            $word->id,
            $word->word,
            $word->response,
            $word->rating
        );
    }

    /**
     * Converte o DTO para um array.
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'word'     => $this->word,
            'response' => $this->response,
            'rating'   => $this->rating,
        ];
    }
}
