<?php

namespace App\Http\Controllers;

use App\Models\Link;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

abstract class BaseController extends Controller
{
    protected function findOwnedLink(int|string $id, string $by = 'id'): ?Link
    {
        return Link::where($by, $id)
            ->where('user_id', auth()->guard('api')->id())
            ->first();
    }

    protected function linkNotFound(): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Link não encontrado ou sem permissão.']],
            404
        );
    }
}
