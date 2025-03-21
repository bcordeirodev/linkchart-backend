<?php

namespace App\Services;

use App\DTOs\WordDTO;
use App\Repositories\WordRepository;

class WordService
{
    protected WordRepository $wordRepository;

    /**
     * Injeção de dependência do repositório.
     *
     * @param  WordRepository  $wordRepository
     */
    public function __construct(WordRepository $wordRepository)
    {
        $this->wordRepository = $wordRepository;
    }

    /**
     * Recupera todas as palavras.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllWords()
    {
        return $this->wordRepository->getAll();
    }

    /**
     * Recupera uma palavra pelo ID.
     *
     * @param  string  $id
     * @return \App\Models\Word|null
     */
    public function getWordById(string $id)
    {
        return $this->wordRepository->find($id);
    }

    /**
     * Cria uma nova palavra.
     *
     * @param  array  $data
     * @return \App\Models\Word
     */
    public function createWord(WordDTO $wordDTO)
    {
        $word = $this->wordRepository->create($wordDTO->toArray());
        return $word;
    }

    /**
     * Atualiza uma palavra existente.
     *
     * @param  string  $id
     * @param  array   $data
     * @return \App\Models\Word|null
     */
    public function updateWord(string $id, WordDTO $wordDTO)
    {
        return $this->wordRepository->update($id, $wordDTO->toArray());
    }

    /**
     * Remove uma palavra pelo ID.
     *
     * @param  string  $id
     * @return \App\Models\Word|null
     */
    public function deleteWord(string $id)
    {
        // Regras de negócio podem ser aplicadas antes da remoção
        return $this->wordRepository->delete($id);
    }
}
