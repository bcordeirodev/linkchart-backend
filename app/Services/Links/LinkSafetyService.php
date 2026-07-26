<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use App\Observability\Otel;
use Illuminate\Support\Facades\Http;

/**
 * Checks a URL against the Google Safe Browsing v4 API for known threats.
 *
 * Called during link creation/update to prevent malware, phishing, unwanted
 * software, and potentially harmful application URLs from being shortened.
 *
 * Configuration: GOOGLE_SAFE_BROWSING_KEY environment variable (read via
 * config/services.php key: google_safe_browsing.key). If the key is missing,
 * the check is skipped and the URL is treated as safe (fail-open).
 *
 * Side effects:
 *   - Outbound HTTP POST to Google Safe Browsing API (timeout 5s).
 *   - Logs via AppLogger::safetyApiUnavailable (key missing),
 *     AppLogger::safetyApiBadResponse (non-2xx HTTP response),
 *     AppLogger::safetyApiError (exception / network failure),
 *     AppLogger::safetyUrlFlagged (threat detected) — channel: app.
 *   - Emits Otel::recordSafetyCheck with result ∈ ok|flagged|bad_response|unavailable
 *     so the safety_check_total counter in Grafana reflects every outcome.
 */
class LinkSafetyService
{
    private const API_URL = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    private const THREAT_TYPES = [
        'MALWARE',
        'SOCIAL_ENGINEERING',
        'UNWANTED_SOFTWARE',
        'POTENTIALLY_HARMFUL_APPLICATION',
    ];

    private const THREAT_LABELS = [
        'MALWARE' => 'malware',
        'SOCIAL_ENGINEERING' => 'phishing',
        'UNWANTED_SOFTWARE' => 'software indesejado',
        'POTENTIALLY_HARMFUL_APPLICATION' => 'aplicação prejudicial',
    ];

    /**
     * Checks a URL against the Google Safe Browsing v4 API.
     *
     * Threat types checked: MALWARE, SOCIAL_ENGINEERING (phishing),
     * UNWANTED_SOFTWARE, POTENTIALLY_HARMFUL_APPLICATION.
     *
     * Fails open (safe=true, api_available=false) if the API key is not
     * configured or if the API returns a non-2xx response or throws.
     *
     * @param  string  $url  The URL to check.
     * @return array{safe: bool, threats: string[], api_available: bool}
     */
    public function checkUrl(string $url): array
    {
        // Layer 1 — local lexical/brand heuristic. Runs first, so it protects
        // even when the Safe Browsing key is missing or the API is down, and
        // short-circuits the outbound HTTP call when it already has a verdict.
        $reasons = $this->heuristicReasons($url);

        if (! empty($reasons)) {
            AppLogger::safetyUrlBlockedHeuristic($url, $reasons);
            Otel::recordSafetyCheck('flagged');

            return ['safe' => false, 'threats' => ['conteúdo suspeito'], 'api_available' => true];
        }

        // Layer 3 — encurtador encadeado. Verificar só a URL enviada faria as duas
        // camadas analisarem o intermediário — que é sempre limpo — e nunca o
        // destino real. Roda DEPOIS da heurística, de propósito: uma URL que já foi
        // reprovada não deve gerar requisição de saída nenhuma.
        $urls = [$url];

        if ($this->isKnownShortener($url)) {
            $final = $this->resolveFinalUrl($url);

            if ($final !== null && $final !== $url) {
                $urls[] = $final;
                $reasons = $this->heuristicReasons($final);

                if (! empty($reasons)) {
                    AppLogger::safetyUrlBlockedHeuristic($final, $reasons);
                    Otel::recordSafetyCheck('flagged');

                    return ['safe' => false, 'threats' => ['conteúdo suspeito'], 'api_available' => true];
                }
            }
        }

        // Layer 4 — Google Safe Browsing (unchanged).
        $apiKey = config('services.google_safe_browsing.key');

        if (empty($apiKey)) {
            AppLogger::safetyApiUnavailable('GOOGLE_SAFE_BROWSING_KEY missing');
            Otel::recordSafetyCheck('unavailable');

            return ['safe' => true, 'threats' => [], 'api_available' => false];
        }

        try {
            $response = Http::timeout(5)->post(self::API_URL.'?key='.$apiKey, [
                'client' => [
                    'clientId' => 'link-charts',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes' => self::THREAT_TYPES,
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    // A API aceita várias entradas por request, então a cadeia
                    // inteira é verificada numa única chamada.
                    'threatEntries' => array_map(fn (string $candidate) => ['url' => $candidate], $urls),
                ],
            ]);

            if ($response->failed()) {
                AppLogger::safetyApiBadResponse($response->status(), $response->body(), $url);
                Otel::recordSafetyCheck('bad_response');

                return ['safe' => true, 'threats' => [], 'api_available' => false];
            }

            $matches = $response->json('matches', []);

            if (empty($matches)) {
                Otel::recordSafetyCheck('ok');

                return ['safe' => true, 'threats' => [], 'api_available' => true];
            }

            $threatTypes = array_map(fn ($m) => $m['threatType'] ?? 'unknown', $matches);
            AppLogger::safetyUrlFlagged($url, $threatTypes);

            $threats = array_values(array_unique(array_map(
                fn ($m) => self::THREAT_LABELS[$m['threatType']] ?? strtolower($m['threatType']),
                $matches
            )));

            Otel::recordSafetyCheck('flagged');

            return ['safe' => false, 'threats' => $threats, 'api_available' => true];
        } catch (\Throwable $e) {
            AppLogger::safetyApiError($e, $url);
            Otel::recordSafetyCheck('unavailable');

            return ['safe' => true, 'threats' => [], 'api_available' => false];
        }
    }

    /**
     * Indica se o host da URL é um encurtador conhecido (host exato ou subdomínio).
     *
     * O gate existe para não transformar a criação de link num buscador de URL
     * arbitrária — ver a justificativa em config/link_safety.php.
     *
     * @param  string  $url  A URL enviada.
     * @return bool True quando vale resolver a cadeia.
     */
    private function isKnownShortener(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ((array) config('link_safety.shortener_hosts', []) as $shortener) {
            $shortener = strtolower((string) $shortener);

            if ($host === $shortener || str_ends_with($host, '.'.$shortener)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve o destino final de uma cadeia de redirects.
     *
     * Faz um HEAD com timeout curto e devolve o último salto. **Fail-open por
     * desenho**: qualquer falha devolve null e a verificação segue apenas com a URL
     * enviada — este código roda num caminho de usuário (criação de link), e não
     * deve introduzir um novo modo de falha bloqueante.
     *
     * Seam `protected` para os testes não dependerem de rede.
     *
     * Nota: isto faz o servidor buscar uma URL fornecida pelo usuário, mas apenas
     * lê a *URL* final — nunca o corpo — e o app já faz buscas assim em
     * LinkPreviewService e LinkHealthCheckJob, então não amplia a superfície.
     *
     * @param  string  $url  A URL enviada.
     * @return string|null O destino final, ou null quando não há redirect ou a
     *                     resolução falhou.
     */
    protected function resolveFinalUrl(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; LinkChartsSafetyBot/1.0; +https://linkcharts.com.br)',
            ])
                ->connectTimeout(2)
                ->timeout(3)
                ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                ->head($url);

            $history = trim((string) $response->header('X-Guzzle-Redirect-History'));

            if ($history === '') {
                return null;
            }

            $hops = array_values(array_filter(array_map('trim', explode(',', $history))));

            return $hops === [] ? null : end($hops);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Evaluates the local Layer 1 heuristic against the URL host and returns the
     * list of reasons it should be blocked. An empty array means the heuristic
     * has no objection (the caller then defers to Safe Browsing).
     *
     * Rules (host-only, to keep false positives low):
     *   - Brand impersonation: host carries a known brand token but is not the
     *     brand's official domain nor a subdomain of it.
     *   - Compound-keyword denylist: host contains a high-signal phishing/scam
     *     substring that is implausible in a legitimate hostname.
     *
     * Returns early with no reasons when the heuristic is disabled, the host
     * cannot be parsed, or the host is explicitly allow-listed.
     *
     * @param  string  $url  The full URL being checked.
     * @return string[] Human-readable block reasons, empty when clean.
     */
    private function heuristicReasons(string $url): array
    {
        if (! config('link_safety.heuristic_enabled', true)) {
            return [];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $this->isAllowedHost($host)) {
            return [];
        }

        $reasons = [];

        foreach ((array) config('link_safety.brands', []) as $token => $officialDomains) {
            if (! $this->hostMentionsBrand($host, (string) $token)) {
                continue;
            }

            if (! $this->hostBelongsToAny($host, (array) $officialDomains)) {
                $reasons[] = "brand_impersonation:{$token}";
            }
        }

        foreach ((array) config('link_safety.blocked_keywords', []) as $keyword) {
            if ($keyword !== '' && str_contains($host, strtolower((string) $keyword))) {
                $reasons[] = "keyword:{$keyword}";
            }
        }

        return $reasons;
    }

    /**
     * Determines whether the host mentions a brand token as a distinct label —
     * bounded by the string edges or by a non-alphanumeric separator (-, ., digit).
     * This is how impersonation hosts are actually built ("instagram-login...",
     * "steam-gift...") and it avoids matching the token inside an unrelated word
     * ("itau" in "capacitau", "caixa" in "caixadagua", "steam" in "steampunk").
     *
     * @param  string  $host  The lowercase host to test.
     * @param  string  $token  The lowercase brand token.
     */
    private function hostMentionsBrand(string $host, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return preg_match('/(?:^|[^a-z0-9])'.preg_quote($token, '/').'(?:[^a-z0-9]|$)/', $host) === 1;
    }

    /**
     * Determines whether the host equals one of the given registrable domains or
     * is a subdomain of one (e.g. "www.instagram.com" belongs to "instagram.com").
     *
     * @param  string  $host  The lowercase host to test.
     * @param  string[]  $domains  Official registrable domains for a brand.
     */
    private function hostBelongsToAny(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower((string) $domain);

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks the host against the configured allow-list, matching an exact host
     * or any subdomain of an allow-listed entry.
     *
     * @param  string  $host  The lowercase host to test.
     */
    private function isAllowedHost(string $host): bool
    {
        return $this->hostBelongsToAny($host, (array) config('link_safety.allowed_hosts', []));
    }
}
