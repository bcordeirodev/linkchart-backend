<?php

namespace App\Http\Controllers;

use App\Logging\AppLogger;
use App\Models\Link;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Abstract base controller for all authenticated API controllers.
 *
 * Provides three shared helpers (findOwnedLink, linkNotFound, serverError) that
 * enforce ownership checks and standardise error responses across the Links and
 * Analytics controller families. This class is not a route target — it is a
 * parent only.
 *
 * Subclasses: LinkController, AnalyticsController.
 */
abstract class BaseController extends Controller
{
    /**
     * Retrieve a link by $id that belongs to the currently authenticated user.
     *
     * Looks up the link using $by column (defaults to 'id'). Returns null when
     * the link does not exist or belongs to a different user, and also when no
     * authenticated user is present on the api guard.
     *
     * @param  int|string  $id  Value to match against the $by column.
     * @param  string  $by  Column name used for the lookup (default: 'id').
     * @return Link|null The owned link, or null when not found / not owned.
     */
    protected function findOwnedLink(int|string $id, string $by = 'id'): ?Link
    {
        $userId = auth()->guard('api')->id();
        if ($userId === null) {
            return null;
        }

        return Link::where($by, $id)->where('user_id', $userId)->first();
    }

    /**
     * Return a standardised 404 JSON response for missing or unauthorised links.
     *
     * Used by controller actions after findOwnedLink returns null to avoid leaking
     * whether a slug/id exists but belongs to another user.
     *
     * @return JsonResponse 404 with a generic "não encontrado" message.
     */
    protected function linkNotFound(): JsonResponse
    {
        return response()->json(
            ['message' => 'Link não encontrado ou sem permissão.'],
            404
        );
    }

    /**
     * Log an unexpected exception and return a standardised 500 JSON response.
     *
     * Delegates logging to AppLogger::httpServerError with the authenticated user
     * id (if available). In debug mode (APP_DEBUG=true) the raw exception message
     * is included in the response body under 'detail'.
     *
     * @param  string  $message  Human-readable description of the operation that failed.
     * @param  \Throwable  $e  The caught exception.
     * @return JsonResponse 500 with $message (and 'detail' in debug mode).
     */
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
