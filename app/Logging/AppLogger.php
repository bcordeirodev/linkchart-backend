<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Semantic logging fachada. Every public method:
 *   - knows which channel to use,
 *   - sets `event=<name>` in the context,
 *   - takes typed arguments to enforce a stable shape per event.
 *
 * Don't introduce raw `Log::*` calls in the codebase. If a use case is missing,
 * either add a method here or use ::event() as an escape hatch.
 *
 * Channels:
 *   redirect, tracking, jobs, auth, http, audit, app (default), errors (mirror).
 */
final class AppLogger
{
    public const REDIRECT_STARTED = 'redirect.started';

    public const REDIRECT_BLOCKED = 'redirect.blocked';

    public const REDIRECT_DISPATCHED = 'redirect.dispatched';

    public const REDIRECT_ERROR = 'redirect.error';

    public const REDIRECT_METRICS_OK = 'redirect.metrics_collected';

    public const REDIRECT_METRICS_FAILED = 'redirect.metrics_failed';

    public const OG_FETCH_SKIPPED = 'og.fetch_skipped';

    public const OG_FETCH_FAILED = 'og.fetch_failed';

    public const OG_FETCH_NON_OK = 'og.fetch_non_ok';

    public const OG_FETCH_SUCCESS = 'og.fetch_succeeded';

    public const OG_FETCH_BUDGET_EXCEEDED = 'og.fetch_budget_exceeded';

    public const TRACKING_CLICK_REGISTERED = 'tracking.click_registered';

    public const TRACKING_LINK_NOT_FOUND = 'tracking.link_not_found';

    public const TRACKING_TEMPORAL_FAIL = 'tracking.temporal_enrichment_failed';

    public const TRACKING_BEHAVIOR_FAIL = 'tracking.behavior_analysis_failed';

    public const GEOIP_DEFAULT_LOCATION = 'geoip.default_location';

    public const GEOIP_FAILED = 'geoip.failed';

    public const USER_AGENT_PARSE_FAILED = 'user_agent.parse_failed';

    public const JOB_STARTED = 'job.started';

    public const JOB_SUCCEEDED = 'job.succeeded';

    public const JOB_FAILED = 'job.failed';

    public const JOB_RETRYING = 'job.retrying';

    public const AUTH_LOGIN_SUCCESS = 'auth.login_success';

    public const AUTH_LOGIN_FAILURE = 'auth.login_failure';

    public const AUTH_REGISTRATION = 'auth.registration';

    public const AUTH_PASSWORD_RESET_REQUESTED = 'auth.password_reset_requested';

    public const AUTH_PASSWORD_RESET_COMPLETED = 'auth.password_reset_completed';

    public const AUTH_EMAIL_VERIFICATION_SENT = 'auth.email_verification_sent';

    public const AUTH_EMAIL_VERIFIED = 'auth.email_verified';

    public const AUTH_JWT_ERROR = 'auth.jwt_error';

    public const AUTH_EXCHANGE_REJECTED = 'auth.exchange_rejected';

    public const AUTH_ERROR = 'auth.error';

    public const HTTP_SLOW_REQUEST = 'http.slow_request';

    public const HTTP_CLIENT_ERROR = 'http.client_error';

    public const HTTP_SERVER_ERROR = 'http.server_error';

    public const AUDIT_LINK_CHANGE = 'audit.link_change';

    public const AUDIT_FAILED = 'audit.failed';

    public const EMAIL_SENT = 'email.sent';

    public const EMAIL_FAILED = 'email.failed';

    public const EMAIL_TEST_FAILED = 'email.test_failed';

    public const LINK_CREATED = 'link.created';

    public const LINK_CLAIMED = 'link.claimed';

    public const LINK_UPDATED = 'link.updated';

    public const LINK_DELETED = 'link.deleted';

    public const LINK_CLICK_LIMIT_REACHED = 'link.click_limit_reached';

    public const ANALYTICS_REQUESTED = 'analytics.requested';

    public const ANALYTICS_CACHE_MISS = 'analytics.cache_miss';

    public const ANALYTICS_SLOW_AGGREGATION = 'analytics.slow_aggregation';

    public const ANALYTICS_ERROR = 'analytics.error';

    public const SAFETY_URL_FLAGGED = 'safety.url_flagged';

    public const SAFETY_URL_BLOCKED_HEURISTIC = 'safety.url_blocked_heuristic';

    public const SAFETY_API_UNAVAILABLE = 'safety.api_unavailable';

    public const SAFETY_API_ERROR = 'safety.api_error';

    // ============================================================
    // REDIRECT (channel: redirect)
    // ============================================================

    /**
     * Hot path — first log line of a redirect after slug resolution.
     *
     * @param  array<string,mixed>  $extra
     */
    public static function redirectStarted(string $slug, ?int $linkId, array $extra = []): void
    {
        self::write('redirect', 'info', self::REDIRECT_STARTED, [
            'slug' => $slug,
            'link_id' => $linkId,
        ] + $extra);
    }

    /**
     * Redirect refused (expired/inactive/not_started/click_limit/not_found).
     */
    public static function redirectBlocked(string $slug, string $reason): void
    {
        self::write('redirect', 'info', self::REDIRECT_BLOCKED, [
            'slug' => $slug,
            'reason' => $reason,
        ]);
    }

    /**
     * Tracking job successfully dispatched to the queue.
     */
    public static function redirectDispatched(int $linkId, string $jobClass): void
    {
        self::write('redirect', 'info', self::REDIRECT_DISPATCHED, [
            'link_id' => $linkId,
            'job' => $jobClass,
        ]);
    }

    /**
     * Unexpected error inside the redirect controller.
     */
    public static function redirectError(string $slug, Throwable $e): void
    {
        self::write('redirect', 'error', self::REDIRECT_ERROR,
            ['slug' => $slug] + self::throwableContext($e));
    }

    /**
     * Final structured event written by RedirectMetricsCollector on success.
     */
    public static function redirectMetricsCollected(string $slug, int $statusCode, float $durationSec, ?string $country): void
    {
        self::write('redirect', 'info', self::REDIRECT_METRICS_OK, [
            'slug' => $slug,
            'status_code' => $statusCode,
            'duration_ms' => round($durationSec * 1000, 2),
            'country' => $country,
        ]);
    }

    /**
     * RedirectMetricsCollector failure (cache unavailable, geoip lookup error, etc).
     */
    public static function redirectMetricsFailed(string $slug, Throwable $e): void
    {
        self::write('redirect', 'error', self::REDIRECT_METRICS_FAILED,
            ['slug' => $slug] + self::throwableContext($e));
    }

    /**
     * OG fetch skipped because URL failed safety checks.
     */
    public static function ogFetchSkipped(string $url, string $reason): void
    {
        self::write('redirect', 'info', self::OG_FETCH_SKIPPED, [
            'url' => $url,
            'reason' => $reason,
        ]);
    }

    /**
     * OG metadata successfully fetched and parsed.
     *
     * @param  string  $source  'oembed' | 'html' — which path produced the data.
     */
    public static function ogFetchSucceeded(string $url, string $source): void
    {
        self::write('redirect', 'info', self::OG_FETCH_SUCCESS, [
            'url' => $url,
            'source' => $source,
        ]);
    }

    /**
     * OG fetch threw an exception.
     */
    public static function ogFetchFailed(string $url, Throwable $e): void
    {
        self::write('redirect', 'warning', self::OG_FETCH_FAILED,
            ['url' => $url] + self::throwableContext($e));
    }

    /**
     * OG fetch returned non-2xx.
     */
    public static function ogFetchNonOk(string $url, int $status): void
    {
        self::write('redirect', 'warning', self::OG_FETCH_NON_OK, [
            'url' => $url,
            'status' => $status,
        ]);
    }

    /**
     * The OG fetch wall-clock budget was exhausted before all fallback
     * strategies were tried; the remaining strategies are skipped and the
     * caller falls back to the bot-passthrough / default-metadata path.
     *
     * @param  string  $url  The destination URL whose metadata fetch was cut short.
     * @param  float  $elapsedMs  Wall-clock spent fetching before the budget tripped.
     */
    public static function ogFetchBudgetExceeded(string $url, float $elapsedMs): void
    {
        self::write('redirect', 'warning', self::OG_FETCH_BUDGET_EXCEEDED, [
            'url' => $url,
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    // ============================================================
    // TRACKING (channel: tracking)
    // ============================================================

    /**
     * Click row written to DB. ctx contains slug, country, state, city, device, referer, utm_data.
     *
     * @param  array<string,mixed>  $context
     */
    public static function trackingClickRegistered(int $clickId, int $linkId, array $context): void
    {
        self::write('tracking', 'info', self::TRACKING_CLICK_REGISTERED,
            ['click_id' => $clickId, 'link_id' => $linkId] + $context);
    }

    public static function trackingLinkNotFound(int $linkId): void
    {
        self::write('tracking', 'warning', self::TRACKING_LINK_NOT_FOUND, ['link_id' => $linkId]);
    }

    public static function trackingTemporalEnrichmentFailed(Throwable $e, array $ctx = []): void
    {
        self::write('tracking', 'warning', self::TRACKING_TEMPORAL_FAIL, $ctx + self::throwableContext($e));
    }

    public static function trackingBehaviorAnalysisFailed(Throwable $e, array $ctx = []): void
    {
        self::write('tracking', 'warning', self::TRACKING_BEHAVIOR_FAIL, $ctx + self::throwableContext($e));
    }

    public static function geoipDefaultLocation(string $ip): void
    {
        self::write('tracking', 'warning', self::GEOIP_DEFAULT_LOCATION, ['ip' => $ip]);
    }

    public static function geoipFailed(string $ip, Throwable $e): void
    {
        self::write('tracking', 'warning', self::GEOIP_FAILED,
            ['ip' => $ip] + self::throwableContext($e));
    }

    public static function userAgentParseFailed(string $ua, Throwable $e): void
    {
        self::write('tracking', 'warning', self::USER_AGENT_PARSE_FAILED,
            ['user_agent' => substr($ua, 0, 200)] + self::throwableContext($e));
    }

    // ============================================================
    // JOBS (channel: jobs)
    // ============================================================

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function jobStarted(string $jobClass, array $payload = []): void
    {
        self::write('jobs', 'info', self::JOB_STARTED, ['job' => $jobClass, 'payload' => $payload]);
    }

    /**
     * @param  array<string,mixed>  $context  Extra fields merged into the log line — e.g.
     *                                        an `outcome` value for jobs whose "succeeded"
     *                                        exit actually covers several distinct cases
     *                                        (sent vs. skipped for various reasons) that
     *                                        would otherwise be indistinguishable in `jobs.log`.
     */
    public static function jobSucceeded(string $jobClass, float $durationMs, array $context = []): void
    {
        self::write('jobs', 'info', self::JOB_SUCCEEDED, [
            'job' => $jobClass,
            'duration_ms' => round($durationMs, 2),
        ] + $context);
    }

    public static function jobFailed(string $jobClass, Throwable $e, int $attempt): void
    {
        self::write('jobs', 'error', self::JOB_FAILED,
            ['job' => $jobClass, 'attempt' => $attempt] + self::throwableContext($e));
    }

    // ============================================================
    // AUTH (channel: auth — email/ip kept raw)
    // ============================================================

    public static function authLoginSuccess(int $userId, string $ip): void
    {
        self::write('auth', 'info', self::AUTH_LOGIN_SUCCESS, ['user_id' => $userId, 'ip' => $ip]);
    }

    public static function authLoginFailure(string $email, string $ip, string $reason): void
    {
        self::write('auth', 'warning', self::AUTH_LOGIN_FAILURE, [
            'email' => $email,
            'ip' => $ip,
            'reason' => $reason,
        ]);
    }

    public static function authRegistration(int $userId, string $email): void
    {
        self::write('auth', 'info', self::AUTH_REGISTRATION, ['user_id' => $userId, 'email' => $email]);
    }

    public static function authPasswordResetRequested(string $email): void
    {
        self::write('auth', 'info', self::AUTH_PASSWORD_RESET_REQUESTED, ['email' => $email]);
    }

    public static function authPasswordResetCompleted(int $userId): void
    {
        self::write('auth', 'info', self::AUTH_PASSWORD_RESET_COMPLETED, ['user_id' => $userId]);
    }

    public static function authEmailVerificationSent(string $email, int $userId): void
    {
        self::write('auth', 'info', self::AUTH_EMAIL_VERIFICATION_SENT, [
            'email' => $email,
            'user_id' => $userId,
        ]);
    }

    public static function authEmailVerified(int $userId): void
    {
        self::write('auth', 'info', self::AUTH_EMAIL_VERIFIED, ['user_id' => $userId]);
    }

    public static function authJwtError(string $reason, string $url): void
    {
        self::write('auth', 'error', self::AUTH_JWT_ERROR, ['reason' => $reason, 'url' => $url]);
    }

    /**
     * Recusa do token exchange do Auth0 (POST /api/auth/auth0-exchange).
     *
     * Todo caminho de rejeição do endpoint passa por aqui. Sem esse registro a
     * falha só existia no RUM do frontend: a auditoria de 2026-08-31 mediu 1 em
     * cada 5 sessões de login barradas por `email_not_verified` sem uma única
     * linha no backend — em 14 dias de log só havia evento de sucesso.
     *
     * Nível warning (não error) de propósito: é recusa esperada de credencial,
     * não defeito do servidor. Fica em `auth.log` e não polui `errors.log`.
     *
     * O canal auth grava e-mail e IP sem máscara (ChannelTap ':skip-redaction'),
     * que é justamente o que permite responder "quem não conseguiu entrar".
     *
     * @param  string  $reason  Motivo estável e agregável — 'userinfo_failed',
     *                          'userinfo_incomplete', 'email_not_verified',
     *                          'email_conflict'.
     * @param  array<string,mixed>  $context  Identificadores do caso (email, auth0_sub, ip, status).
     */
    public static function authExchangeRejected(string $reason, array $context = []): void
    {
        self::write('auth', 'warning', self::AUTH_EXCHANGE_REJECTED, ['reason' => $reason] + $context);
    }

    /**
     * Escape hatch for auth-domain errors that don't have a dedicated method.
     *
     * @param  array<string,mixed>  $extra
     */
    public static function authError(string $event, Throwable $e, array $extra = []): void
    {
        self::write('auth', 'error', self::AUTH_ERROR,
            ['source_event' => $event] + $extra + self::throwableContext($e));
    }

    // ============================================================
    // HTTP (channel: http)
    // ============================================================

    /**
     * Resposta 4xx servida pelo Laravel (canal http, nível warning).
     *
     * Escrito pelo middleware {@see \App\Http\Middleware\LogHttpErrors} para
     * TODA resposta de erro do cliente — tanto as que nascem de exceção
     * (404/422/429 renderizados em bootstrap/app.php) quanto as que o
     * controller devolve na mão (401/403/409 do fluxo de auth). Sem ele o
     * canal `http` só recebia 5xx e ficou 3 meses e meio sem uma linha.
     *
     * @param  string  $route  Nome da rota ou template de URI — nunca o path cru,
     *                         para o log agregar por endpoint.
     * @param  int  $status  Código HTTP da resposta (400–499).
     * @param  array<string,mixed>  $context  method, path, user_id, error_code.
     */
    public static function httpClientError(string $route, int $status, array $context = []): void
    {
        self::write('http', 'warning', self::HTTP_CLIENT_ERROR, [
            'route' => $route,
            'status' => $status,
        ] + $context);
    }

    /**
     * Resposta 5xx SEM exceção associada (canal http, nível error).
     *
     * É o caso do controller que devolve 500 na mão depois de engolir a
     * exceção no próprio catch — o renderer de exceção do bootstrap nunca vê
     * essas respostas. Quando existe exceção, quem loga é {@see httpServerError},
     * que carrega o stack; o middleware não duplica (ver LogHttpErrors::ATTR_LOGGED).
     *
     * @param  string  $route  Nome da rota ou template de URI.
     * @param  int  $status  Código HTTP da resposta (500–599).
     * @param  array<string,mixed>  $context  method, path, user_id, error_code.
     */
    public static function httpServerErrorResponse(string $route, int $status, array $context = []): void
    {
        self::write('http', 'error', self::HTTP_SERVER_ERROR, [
            'route' => $route,
            'status' => $status,
        ] + $context);
    }

    /**
     * Resposta 5xx produzida por exceção não tratada (canal http, nível error).
     *
     * @param  string  $route  Path ou rota que falhou.
     * @param  \Throwable  $e  Exceção não tratada que produziu o 500.
     * @param  int|null  $userId  Usuário autenticado, quando houver.
     */
    public static function httpServerError(string $route, Throwable $e, ?int $userId): void
    {
        self::write('http', 'error', self::HTTP_SERVER_ERROR,
            ['route' => $route, 'user_id' => $userId] + self::throwableContext($e));
    }

    // ============================================================
    // AUDIT (channel: audit — email/ip kept raw)
    // ============================================================

    /**
     * @param  array<string,mixed>  $context
     */
    public static function auditFailed(Throwable $e, array $context = []): void
    {
        self::write('audit', 'error', self::AUDIT_FAILED, $context + self::throwableContext($e));
    }

    // ============================================================
    // LINKS (channel: app)
    // ============================================================

    /**
     * A short link was just created (authenticated or public/guest flow).
     *
     * Logged to the `app` channel so link creation is visible in the growth
     * dashboard (there is otherwise no "link created" signal — it is a plain
     * DB insert). `host` is the destination host (parsed from the original
     * URL), which powers "what is being shortened" reports without exposing
     * the full target URL.
     *
     * @param  \App\Models\Link  $link  the freshly-created link
     * @param  bool  $isPublic  true when created via the public/guest flow
     */
    public static function linkCreated(\App\Models\Link $link, bool $isPublic): void
    {
        $host = parse_url((string) $link->original_url, PHP_URL_HOST) ?: 'unknown';

        self::write('app', 'info', self::LINK_CREATED, [
            'slug' => $link->slug,
            'host' => $host,
            'link_id' => $link->id,
            'is_public' => $isPublic,
            'is_guest' => empty($link->user_id),
        ]);
    }

    /**
     * Um link anônimo acabou de ser reivindicado pelo seu criador.
     *
     * Canal `app`, nível info. É a ÚNICA fonte de verdade da métrica de sucesso
     * da feature claim-your-link: a coluna `claim_token_hash` só diz se o link
     * ainda é reivindicável, não registra a transição anônimo → dono. Contar
     * `link.claimed` no período responde "quantos convidados viraram conta
     * levando os cliques que já tinham".
     *
     * Não recebe o token (nem em claro nem hasheado): a prova já foi consumida
     * e log não é lugar de segredo.
     *
     * @param  int  $linkId  id do link que trocou de dono.
     * @param  int  $userId  id do usuário que passou a ser o dono.
     * @param  string  $slug  slug reivindicado, para correlacionar com `link.created`.
     */
    public static function linkClaimed(int $linkId, int $userId, string $slug): void
    {
        self::write('app', 'info', self::LINK_CLAIMED, [
            'link_id' => $linkId,
            'user_id' => $userId,
            'slug' => $slug,
        ]);
    }

    // ============================================================
    // EMAIL (channel: app)
    // ============================================================

    public static function emailSent(string $to, string $type, string $provider): void
    {
        self::write('app', 'info', self::EMAIL_SENT, ['to' => $to, 'type' => $type, 'provider' => $provider]);
    }

    public static function emailFailed(string $to, string $type, Throwable $e): void
    {
        self::write('app', 'error', self::EMAIL_FAILED,
            ['to' => $to, 'type' => $type] + self::throwableContext($e));
    }

    public static function emailTestFailed(Throwable $e, array $ctx = []): void
    {
        self::write('app', 'error', self::EMAIL_TEST_FAILED, $ctx + self::throwableContext($e));
    }

    // ============================================================
    // ANALYTICS (channel: app)
    // ============================================================

    public static function analyticsError(Throwable $e, array $ctx = []): void
    {
        self::write('app', 'error', self::ANALYTICS_ERROR, $ctx + self::throwableContext($e));
    }

    // ============================================================
    // SAFETY (channel: app)
    // ============================================================

    /** @param  array<int,string>  $threats */
    public static function safetyUrlFlagged(string $url, array $threats): void
    {
        self::write('app', 'warning', self::SAFETY_URL_FLAGGED, ['url' => $url, 'threats' => $threats]);
    }

    /**
     * Logs a URL blocked by the local Layer 1 heuristic (brand impersonation or
     * compound-keyword denylist) before the Safe Browsing call. The matched
     * reasons are recorded so the heuristic lists can be tuned from production
     * signal and false positives diagnosed.
     *
     * @param  string  $url  The URL that was blocked.
     * @param  string[]  $reasons  Human-readable reasons for the block.
     */
    public static function safetyUrlBlockedHeuristic(string $url, array $reasons): void
    {
        self::write('app', 'warning', self::SAFETY_URL_BLOCKED_HEURISTIC, ['url' => $url, 'reasons' => $reasons]);
    }

    public static function safetyApiUnavailable(string $reason): void
    {
        self::write('app', 'warning', self::SAFETY_API_UNAVAILABLE, ['reason' => $reason]);
    }

    public static function safetyApiError(Throwable $e, string $url): void
    {
        self::write('app', 'error', self::SAFETY_API_ERROR,
            ['url' => $url] + self::throwableContext($e));
    }

    /**
     * Logs a non-2xx response from the Safe Browsing API, preserving the real
     * HTTP status and a truncated response body so a misconfigured key
     * (403 PERMISSION_DENIED, 400 API_KEY_INVALID, 429, ...) is diagnosable.
     *
     * @param  int  $status  The HTTP status code returned by the API.
     * @param  string  $body  The raw response body (truncated to 500 chars).
     * @param  string  $url  The URL that was being checked.
     */
    public static function safetyApiBadResponse(int $status, string $body, string $url): void
    {
        self::write('app', 'error', self::SAFETY_API_ERROR, [
            'url' => $url,
            'status' => $status,
            'body' => Str::limit($body, 500),
        ]);
    }

    // ============================================================
    // ADMIN (channel: audit)
    // ============================================================

    /**
     * Registra uma ação do módulo admin no canal de auditoria.
     *
     * Convenção de contexto: SÓ identificadores (admin_id, page, sort...) —
     * nunca payloads: o canal audit grava sem redação de PII, e logar a
     * listagem persistiria emails de terceiros em plaintext.
     *
     * @param  string  $event  Nome do evento (ex.: 'admin.users_viewed').
     * @param  array<string, mixed>  $context  Identificadores da ação.
     */
    public static function adminAction(string $event, array $context = []): void
    {
        self::event('audit', 'info', $event, $context);
    }

    // ============================================================
    // ESCAPE HATCH
    // ============================================================

    /**
     * Generic escape hatch. Use only when an event genuinely doesn't fit
     * any of the typed methods. Repeated use of the same $event string is
     * a signal to add a dedicated method above.
     *
     * @param  array<string,mixed>  $context
     */
    public static function event(string $channel, string $level, string $event, array $context = []): void
    {
        self::write($channel, $level, $event, $context);
    }

    // ============================================================
    // INTERNAL
    // ============================================================

    /**
     * Write to a domain channel, applying the standard 'event' context tag.
     *
     * For most channels the configured stack ('<channel>' → ['<channel>_file', 'errors'])
     * fans out automatically. For 'auth' and 'audit' we bypass the stack and write
     * directly to '<channel>_file' so the raw email/ip aren't redacted by the
     * 'errors' sub-channel's processors. Error-level events from auth/audit are
     * still mirrored to 'errors' (with redaction applied there).
     *
     * @param  array<string,mixed>  $context
     */
    private static function write(string $channel, string $level, string $event, array $context): void
    {
        $payload = ['event' => $event] + $context;

        if ($channel === 'auth' || $channel === 'audit') {
            Log::channel($channel.'_file')->{$level}($event, $payload);
            if (in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
                Log::channel('errors')->{$level}($event, $payload);
            }

            return;
        }

        Log::channel($channel)->{$level}($event, $payload);
    }

    /**
     * @return array{error: string, exception: string, file: string, line: int}
     */
    private static function throwableContext(Throwable $e): array
    {
        return [
            'error' => $e->getMessage(),
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
}
