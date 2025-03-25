<?php

namespace App\Repositories;

use App\Models\Word;

class WordRepository
{
    /**
     * Retorna todos os registros da tabela 'word'.
     *
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getAll()
    {
        return Word::all();
    }

    /**
     * Retorna um registro específico com base no ID.
     *
     * @param  string  $id
     * @return \App\Models\Word|null
     */
    public function find(string $id)
    {
        return Word::find($id);
    }
   

    /**
     * Cria um novo registro na tabela 'word'.
     *
     * @param  array  $data
     * @return \App\Models\Word
     */
    public function create(array $data)
    {
        return Word::create($data);
    }

    /**
     * Atualiza um registro existente na tabela 'word'.
     *
     * @param  string  $id
     * @param  array   $data
     * @return \App\Models\Word|null
     */
    public function update(string $id, array $data)
    {
        $word = Word::find($id);

        if ($word) {
            $word->update($data);
        }

        return $word;
    }
    

    /**
     * Exclui um registro específico com base no ID.
     *
     * @param  string  $id
     * @return \App\Models\Word|null
     */
    public function delete(string $id)
    {
        $word = Word::find($id);

        if ($word) {
            $word->delete();
        }

        return $word;
    }
}
