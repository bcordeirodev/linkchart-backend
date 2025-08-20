<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WordController;

Route::get('/', function () {
    return response()->json([
        'message' => 'Link Charts API is running!',
        'version' => '1.0.0',
        'status' => 'active'
    ]);
});

// Rota que utiliza o método index() do WordController para retornar todas as palavras.
// Route::get('/teste', [WordController::class, 'index']);
// Route::post('/teste', [WordController::class, 'store']);
// Route::get('/teste/{id}', [WordController::class, 'show']);
// Route::put('/teste/{id}', [WordController::class, 'update']);
