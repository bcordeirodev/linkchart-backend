<?php

namespace App\Http\Controllers;

use App\Logging\AppLogger;
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

    protected function serverError(string $message, \Throwable $e): JsonResponse
    {
        AppLogger::httpServerError($message, $e, optional(request()?->user())->id ?? null);

        $body = ['message' => $message];
        if (config('app.debug')) {
            $body['detail'] = $e->getMessage();
        }

        return response()->json($body, 500);
    }
}
