<?php

namespace App\Http\Controllers;

use App\Models\Link;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

abstract class BaseController extends Controller
{
    protected function findOwnedLink(int|string $id, string $by = 'id'): ?Link
    {
        $userId = auth()->guard('api')->id();
        if ($userId === null) {
            return null;
        }
        return Link::where($by, $id)->where('user_id', $userId)->first();
    }

    protected function linkNotFound(): JsonResponse
    {
        return response()->json(
            ['message' => 'Link não encontrado ou sem permissão.'],
            404
        );
    }
}
