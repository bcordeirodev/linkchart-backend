<?php

namespace App\Support;

use App\Logging\AppLogger;
use Illuminate\Http\Request;

/**
 * Resolves the real client IP from a set of proxy/CDN headers.
 *
 * This is the SINGLE source of truth for client-IP resolution. It is shared by
 * the redirect hot path: RedirectMetricsCollector (middleware, live Request) and
 * LinkTrackingService::resolveRealUserIP (queued job, serialized payload). Because
 * those two callers have different input shapes, the resolver is intentionally
 * framework-agnostic: it takes already-extracted raw header values plus a fallback
 * IP, never a Request object.
 *
 * Priority chain (first valid public IP wins):
 *   1. ?real_ip query parameter (dev/proxy override sent by the frontend)
 *   2. X-Real-IP header (nginx proxy)
 *   3. X-Forwarded-For header, first token (CDN/load balancer)
 *   4. CF-Connecting-IP header (Cloudflare)
 *   5. fallback IP (Request::ip(), or '127.0.0.1' when that is empty too)
 *
 * In production, private/reserved ranges are excluded via FILTER_FLAG_NO_PRIV_RANGE
 * | FILTER_FLAG_NO_RES_RANGE — this prevents internal proxy IPs from being stored.
 *
 * SECURITY TODO (deliberately NOT fixed here — behavior-preserving dedup only):
 * trusting the FIRST token of X-Forwarded-For is spoofable by clients. Fixing it
 * changes the IP trust semantics and impacts geo/fraud/rate-limiting/LGPD; it
 * requires the production proxy config (trusted-proxy list) and is a separate task.
 */
class ClientIpResolver
{
    /**
     * Ordered source labels for the resolution priority chain. Used both as the
     * iteration order and as the label reported to the optional $onResolved hook.
     */
    public const SOURCE_QUERY_PARAM = 'query_param';

    public const SOURCE_X_REAL_IP = 'X-Real-IP';

    public const SOURCE_X_FORWARDED_FOR = 'X-Forwarded-For';

    public const SOURCE_CF_CONNECTING_IP = 'Cloudflare';

    public const SOURCE_FALLBACK = 'fallback';

    /**
     * Resolve o IP real do cliente para a request atual.
     *
     * Com a cadeia de proxies configurada corretamente — Nginx da borda aplicando
     * `real_ip_header CF-Connecting-IP` restrito às faixas da Cloudflare, e
     * TrustProxies confiando na bridge do Docker — `$request->ip()` já é a resposta
     * autoritativa: o Symfony caminha o X-Forwarded-For da direita para a esquerda e
     * descarta os saltos confiáveis, então um token forjado pelo cliente é ignorado.
     *
     * Fora de produção um override `?real_ip=` é honrado, para o desenvolvimento
     * local conseguir simular origens arbitrárias.
     *
     * @param  Request  $request  A request atual.
     * @return string O IP do cliente; nunca vazio.
     */
    public function fromRequest(Request $request): string
    {
        if (! $this->isProduction()) {
            $override = $request->query('real_ip');

            if (is_string($override) && filter_var($override, FILTER_VALIDATE_IP)) {
                return $override;
            }
        }

        $ip = $request->ip() ?: '127.0.0.1';

        if ($this->isProduction()) {
            $this->warnIfEdgeChainLooksBroken($request, $ip);
        }

        return $ip;
    }

    /**
     * Verifica se o IP é bem-formado e publicamente roteável.
     *
     * Usado para detectar configuração errada da cadeia de proxies: em produção, um
     * IP privado resolvido significa que a borda não está entregando o IP do cliente.
     *
     * @param  string  $ip  O endereço a validar.
     * @return bool True quando é um IP público válido.
     */
    public function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Avisa quando a cadeia de proxy da borda parece não estar entregando o IP do
     * cliente. Dois sintomas distintos, e o segundo é o que importa:
     *
     *  - IP privado resolvido: TrustProxies ou o Nginx interno do container estão
     *    errados.
     *  - IP resolvido diferente do CF-Connecting-IP: o `real_ip_header` da borda não
     *    está sendo aplicado, ou seja estamos gravando o IP da **borda da
     *    Cloudflare** no lugar do cliente. Este é o modo de falha SILENCIOSO — o IP
     *    da borda é público, então a checagem de IP privado não o pega. Comparar
     *    contra o header dispensa manter uma cópia das faixas da CF aqui: quando a
     *    borda está correta, os dois valores são idênticos por construção.
     *
     * @param  Request  $request  A request atual.
     * @param  string  $ip  O IP já resolvido.
     */
    private function warnIfEdgeChainLooksBroken(Request $request, string $ip): void
    {
        if (! $this->isPublicIp($ip)) {
            AppLogger::event('app', 'warning', 'ip.private_resolved_in_production', [
                'ip' => $ip,
                'hint' => 'cadeia de proxy confiavel mal configurada: checar TrustProxies e o nginx do container',
            ]);

            return;
        }

        $cfConnectingIp = $request->header('CF-Connecting-IP');

        if (is_string($cfConnectingIp) && $cfConnectingIp !== '' && $cfConnectingIp !== $ip) {
            AppLogger::event('app', 'warning', 'ip.edge_chain_mismatch', [
                'resolved_ip' => $ip,
                'cf_connecting_ip' => $cfConnectingIp,
                'hint' => 'real_ip_header nao aplicado na borda: provavel gravacao do IP da cloudflare',
            ]);
        }
    }

    /**
     * Indica se a aplicação está rodando em produção.
     *
     * @return bool True em produção.
     */
    private function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    /**
     * Resolves the real client IP from already-extracted header candidates.
     *
     * Candidate keys are the source constants above (excluding SOURCE_FALLBACK).
     * Missing/null/empty candidates are skipped. The X-Forwarded-For value may be a
     * comma-separated chain ("client, proxy1, proxy2"); only the first token is used.
     *
     * The optional $onResolved callback is invoked exactly once with the winning
     * source label, the resolved IP, and a context array (e.g. the full XFF chain),
     * letting callers emit per-source debug logs without duplicating the chain logic.
     *
     * @param  array<string, string|null>  $candidates  Raw header values keyed by source constant.
     * @param  string  $fallback  Fallback IP used when no candidate is a valid public IP.
     * @param  (callable(string $source, string $ip, array<string, mixed> $context): void)|null  $onResolved  Optional hook fired with the winning source.
     * @return string A valid IP address string (never empty; falls back to '127.0.0.1' upstream).
     */
    public function resolve(array $candidates, string $fallback, ?callable $onResolved = null): string
    {
        if ($ip = $this->validCandidate($candidates[self::SOURCE_QUERY_PARAM] ?? null)) {
            $onResolved && $onResolved(self::SOURCE_QUERY_PARAM, $ip, []);

            return $ip;
        }

        if ($ip = $this->validCandidate($candidates[self::SOURCE_X_REAL_IP] ?? null)) {
            $onResolved && $onResolved(self::SOURCE_X_REAL_IP, $ip, []);

            return $ip;
        }

        $forwardedFor = $candidates[self::SOURCE_X_FORWARDED_FOR] ?? null;
        if ($forwardedFor !== null && $forwardedFor !== '') {
            // X-Forwarded-For may carry multiple IPs: "client, proxy1, proxy2".
            $clientIP = trim(explode(',', $forwardedFor)[0]);
            if ($this->isValidIp($clientIP)) {
                $onResolved && $onResolved(self::SOURCE_X_FORWARDED_FOR, $clientIP, ['full_chain' => $forwardedFor]);

                return $clientIP;
            }
        }

        if ($ip = $this->validCandidate($candidates[self::SOURCE_CF_CONNECTING_IP] ?? null)) {
            $onResolved && $onResolved(self::SOURCE_CF_CONNECTING_IP, $ip, []);

            return $ip;
        }

        $fallbackIP = $fallback !== '' ? $fallback : '127.0.0.1';
        $onResolved && $onResolved(self::SOURCE_FALLBACK, $fallbackIP, []);

        return $fallbackIP;
    }

    /**
     * Trims a raw candidate value and returns it only when it is a valid IP.
     *
     * @param  string|null  $raw  Raw header value (may be null/empty).
     * @return string|null The trimmed, valid IP, or null when absent/invalid.
     */
    private function validCandidate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $clean = trim($raw);

        return $this->isValidIp($clean) ? $clean : null;
    }

    /**
     * Validates that an IP is well-formed and, in production, publicly routable.
     *
     * In production, private/reserved ranges are rejected. In every other
     * environment any syntactically valid IP is accepted.
     *
     * @param  string  $ip  The IP address to validate.
     * @return bool True when the IP is acceptable for storage.
     */
    public function isValidIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (config('app.env') === 'production') {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
