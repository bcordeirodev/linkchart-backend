<?php

use App\Http\Controllers\WordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);

// Rotas protegidas pelo middleware de autenticação JWT
Route::group(['middleware' => ['auth:api']], function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
});

Route::prefix('word')->group(function () {
    Route::post('/', [WordController::class, 'store']);
    Route::get('/', [WordController::class, 'index']);
    Route::get('/{id}', [WordController::class, 'show']);
    Route::patch('/{id}', [WordController::class, 'update']);
    Route::delete('/{id}', [WordController::class, 'destroy']);
});