<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreApiKeyRequest;
use App\Logging\AppLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Gestão de API keys (tokens Sanctum) da API pública.
 *
 * Owns the /api/api-keys route family, protected by the PANEL auth
 * (api.auth:api + verified + throttle:api-keys — 10/min por usuário). The
 * Sanctum tokens issued here authenticate only the public API (/api/v1/*);
 * they never authenticate these management routes (guard isolation).
 *
 * Security invariants:
 *   - The full plaintext token appears exactly once, in the 201 response of
 *     store(). Sanctum persists only the sha256 hash.
 *   - Listing exposes a 4-char preview ("…a1b2") captured at creation time
 *     into personal_access_tokens.token_preview.
 *   - The token value is NEVER logged — audit events carry only ids/names.
 *   - Máximo de MAX_KEYS_PER_USER chaves por usuário (422 ao exceder).
 *
 * Routes overview:
 *   GET    /api/api-keys        → index
 *   POST   /api/api-keys        → store
 *   DELETE /api/api-keys/{id}   → destroy
 */
class ApiKeyController extends Controller
{
    /**
     * Número máximo de API keys ativas por usuário.
     */
    public const MAX_KEYS_PER_USER = 5;

    /**
     * Listar API keys
     *
     * GET /api/api-keys
     *
     * Return the authenticated user's API keys, newest first. Each item shows
     * only the identifying metadata — the full token is unrecoverable after
     * creation (Sanctum stores its sha256 hash); `token_preview` carries the
     * last 4 characters ("…a1b2") so keys can be told apart.
     *
     * Middleware: api.auth:api, verified, throttle:api-keys
     * Auth: required (panel JWT)
     *
     * Response shape: NormalizeApiResponse envelope:
     *   { data: [{ id, name, token_preview, last_used_at, created_at }] }
     *
     * @param  Request  $request  Current HTTP request (panel-authenticated).
     */
    public function index(Request $request): JsonResponse
    {
        $keys = $request->user('api')->tokens()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'token_preview' => $token->token_preview !== null ? '…'.$token->token_preview : null,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => $keys]);
    }

    /**
     * Criar API key
     *
     * POST /api/api-keys
     *
     * Create a new Sanctum personal access token for the authenticated user.
     * The plaintext token (format "{id}|{random}") is returned ONLY in this
     * response — store it immediately; it cannot be retrieved again. The last
     * 4 characters are persisted as `token_preview` for later identification.
     *
     * Business rule: at most 5 keys per user (422 when exceeded).
     *
     * Middleware: api.auth:api, verified, throttle:api-keys
     * Auth: required (panel JWT)
     *
     * Response shape: NormalizeApiResponse envelope: { data: { id, name, token } } (201)
     *
     * @param  StoreApiKeyRequest  $request  Validated payload ({ name: string, max 60 }).
     *
     * @throws \Illuminate\Validation\ValidationException (handled by StoreApiKeyRequest)
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $user = $request->user('api');

        if ($user->tokens()->count() >= self::MAX_KEYS_PER_USER) {
            return response()->json([
                'error' => 'Limite de chaves atingido.',
                'message' => 'Cada conta pode ter no máximo '.self::MAX_KEYS_PER_USER
                    .' chaves de API. Revogue uma chave existente para criar outra.',
            ], 422);
        }

        $newToken = $user->createToken($request->validated('name'));

        /** @var PersonalAccessToken $accessToken */
        $accessToken = $newToken->accessToken;

        // Captura o preview (últimos 4 chars do plaintext) no único momento em
        // que ele existe. forceFill: a coluna não é fillable no model do Sanctum.
        $accessToken->forceFill([
            'token_preview' => substr($newToken->plainTextToken, -4),
        ])->save();

        // Nunca logar o token — apenas metadados.
        AppLogger::event('app', 'info', 'api_key.created', [
            'user_id' => $user->id,
            'api_key_id' => $accessToken->id,
            'api_key_name' => $accessToken->name,
        ]);

        return response()->json([
            'data' => [
                'id' => $accessToken->id,
                'name' => $accessToken->name,
                'token' => $newToken->plainTextToken,
            ],
        ], 201);
    }

    /**
     * Revogar API key
     *
     * DELETE /api/api-keys/{id}
     *
     * Permanently revoke one of the authenticated user's API keys. Requests
     * authenticated with the revoked token start failing with 401 immediately.
     * Returns 404 when the key does not exist OR belongs to another user —
     * the response never reveals foreign key ids.
     *
     * Middleware: api.auth:api, verified, throttle:api-keys
     * Auth: required (panel JWT)
     * Owner check: yes — lookup scoped to the user's tokens.
     *
     * Response shape: NormalizeApiResponse envelope: { data: { deleted: true } }
     *
     * @param  Request  $request  Current HTTP request (panel-authenticated).
     * @param  string  $id  Numeric API key ID (route-constrained to digits).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user('api');

        /** @var PersonalAccessToken|null $token */
        $token = $user->tokens()->whereKey($id)->first();

        if (! $token) {
            return response()->json(['message' => 'Chave de API não encontrada.'], 404);
        }

        $token->delete();

        AppLogger::event('app', 'info', 'api_key.revoked', [
            'user_id' => $user->id,
            'api_key_id' => (int) $id,
            'api_key_name' => $token->name,
        ]);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
