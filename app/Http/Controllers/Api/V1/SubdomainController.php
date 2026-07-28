<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UserSubdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * API pública v1 de endereços personalizados (autenticação via API key/Sanctum).
 *
 * Owns GET /api/v1/subdomains — the discovery half of the by-name `subdomain`
 * field on POST /api/v1/links: a script lists the account's active addresses
 * here and passes one of the returned `subdomain` labels on link creation.
 *
 * The internal numeric id is deliberately NOT exposed: the public contract is
 * by name, and the id is useless anywhere else in this API.
 */
class SubdomainController extends Controller
{
    /**
     * Listar endereços personalizados
     *
     * GET /api/v1/subdomains
     *
     * Return the token owner's ACTIVE custom addresses, oldest first. The
     * first item is the account default — the address POST /api/v1/links
     * uses when the `subdomain` field is absent — flagged via `is_default`.
     *
     * Middleware: auth:sanctum, throttle:public-api
     * Auth: required (Bearer API key)
     *
     * Response shape: NormalizeApiResponse envelope:
     *   { data: [{ subdomain, host, is_default, created_at }] }
     *
     * @param  Request  $request  Current HTTP request (Sanctum-authenticated).
     */
    public function index(Request $request): JsonResponse
    {
        $domain = config('app.domain');

        $items = UserSubdomain::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn (UserSubdomain $sub, int $index): array => [
                'subdomain' => $sub->subdomain,
                'host' => $sub->subdomain.'.'.$domain,
                'is_default' => $index === 0,
                'created_at' => $sub->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $items]);
    }
}
