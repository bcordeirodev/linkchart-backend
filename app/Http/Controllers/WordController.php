<?php

namespace App\Http\Controllers;

use App\DTOs\WordDTO;
use App\Http\Resources\WordResource;
use App\Services\WordService;
use Illuminate\Http\Request;

class WordController 
{
    protected WordService $wordService;

    /**
     * Injeta a dependência da service.
     *
     * @param WordService $wordService
     */
    public function __construct(WordService $wordService)
    {
        $this->wordService = $wordService;
    }

    /**
     * Exibe uma lista de todas as palavras.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $words = $this->wordService->getAllWords();
        return WordResource::collection($words);
    }

    /**
     * Exibe uma única palavra pelo ID.
     *
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(string $id)
    {
        $word = $this->wordService->getWordById($id);

        if (!$word) {
            return response()->json(['message' => 'Palavra não encontrada.'], 404);
        }

        return new WordResource($word);
    }

    /**
     * Cria uma nova palavra.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validação dos dados recebidos
        $request->validate([
            'word'     => 'required|string|max:255',
            'response' => 'required|string',
            'rating'   => 'required|integer',
        ]);
        
        $wordDTO = WordDTO::fromRequest($request);
        $word = $this->wordService->createWord($wordDTO);
        
        return new WordResource($word);
    }

    /**
     * Atualiza uma palavra existente.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, string $id)
    {
        // Validação dos dados enviados para atualização
        $validated = $request->validate([
            'word'     => 'sometimes|required|string|max:255',
            'response' => 'sometimes|required|string',
            'rating'   => 'sometimes|required|integer',
        ]);

        $wordDTO = WordDTO::fromRequest($request);
        $word = $this->wordService->updateWord($id, $wordDTO);

        if (!$word) {
            return response()->json(['message' => 'Palavra não encontrada.'], 404);
        }

        return response()->json($word);
    }

    /**
     * Remove uma palavra pelo ID.
     *
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(string $id)
    {
        $word = $this->wordService->deleteWord($id);

        if (!$word) {
            return response()->json(['message' => 'Palavra não encontrada.'], 404);
        }

        return response()->json(['message' => 'Palavra removida com sucesso.']);
    }
}
