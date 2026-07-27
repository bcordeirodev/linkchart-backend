<?php

namespace App\Http\Middleware;

use App\Logging\AppLogger;
use App\Support\ClientIpResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware específico para coletar métricas detalhadas de redirecionamentos
 * Usado APENAS no endpoint público /r/{slug}
 */
class RedirectMetricsCollector
{
    /**
     * @param  ClientIpResolver  $clientIpResolver  Resolvedor compartilhado do IP do cliente — única
     *                                              fonte de verdade (também usado por
     *                                              LinkTrackingService::resolveRealUserIP).
     */
    public function __construct(
        private readonly ClientIpResolver $clientIpResolver,
    ) {}

    /**
     * Collects detailed redirect metrics for the request and forwards it down the pipeline.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  Closure  $next  The next middleware in the pipeline.
     * @return Response The downstream response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $slug = $request->route('slug');
        $ip = $this->getRealUserIP($request); // 🌐 USAR MESMA LÓGICA DO LinkTrackingService
        $userAgent = $request->userAgent();
        $referer = $request->headers->get('referer');

        AppLogger::event('redirect', 'debug', 'redirect_metrics.starting', [
            'slug' => $slug,
            'ip' => $ip,
            // userAgent() é null quando a request não manda o header (scanners fazem
            // isso). Truncar direto passava null para substr() — deprecation hoje,
            // TypeError num PHP futuro, no hot path de cliques. Mantém null em vez de
            // virar '' para o log continuar distinguindo "não veio" de "veio vazio".
            'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 100),
        ]);

        // Processar redirecionamento
        $response = $next($request);

        $responseTime = microtime(true) - $startTime;
        $statusCode = $response->getStatusCode();

        AppLogger::event('redirect', 'debug', 'redirect_metrics.response_processed', [
            'slug' => $slug,
            'status_code' => $statusCode,
            'response_time' => $responseTime,
        ]);

        // Coletar métricas específicas de redirecionamento com try-catch
        try {
            $this->collectRedirectMetrics([
                'slug' => $slug,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'referer' => $referer,
                'status_code' => $statusCode,
                'response_time' => $responseTime,
                'timestamp' => now(),
                'success' => $statusCode >= 200 && $statusCode < 400,
                'country' => $this->getCountryFromIp($ip),
                'device' => $this->getDeviceType($userAgent),
                'utm_params' => $request->query(),
            ]);

            \App\Observability\Otel::recordRedirect(
                $statusCode,
                $responseTime,
                $this->getCountryFromIp($ip),
                $this->getDeviceType($userAgent),
            );

            AppLogger::redirectMetricsCollected(
                $slug,
                $statusCode,
                $responseTime,
                $this->getCountryFromIp($ip)
            );
        } catch (\Throwable $e) {
            try {
                AppLogger::redirectMetricsFailed($slug, $e);
            } catch (\Throwable $logError) {
                error_log('FALLBACK_LOG: RedirectMetricsCollector failed for slug='.$slug.' error='.$e->getMessage());
            }
            // Não falhar a requisição por causa das métricas
        }

        return $response;
    }

    /**
     * Coleta métricas específicas de redirecionamento
     */
    private function collectRedirectMetrics(array $metrics): void
    {
        AppLogger::event('redirect', 'debug', 'redirect_metrics.collect_called', [
            'slug' => $metrics['slug'] ?? 'unknown',
        ]);

        try {
            // Verificar se Cache está disponível
            if (! $this->isCacheAvailable()) {
                AppLogger::event('redirect', 'debug', 'redirect_metrics.cache_unavailable_skip', []);

                return;
            }

            AppLogger::event('redirect', 'debug', 'redirect_metrics.cache_available', []);

            $hour = now()->format('Y-m-d-H');
            $day = now()->format('Y-m-d');

            AppLogger::event('redirect', 'debug', 'redirect_metrics.time_vars_set', [
                'hour' => $hour,
                'day' => $day,
            ]);

            // Métricas por hora de redirecionamentos
            $hourKey = "redirect_metrics:hour:{$hour}";
            AppLogger::event('redirect', 'debug', 'redirect_metrics.getting_hourly', ['key' => $hourKey]);

            $hourMetrics = Cache::get($hourKey, [
                'total_redirects' => 0,
                'successful_redirects' => 0,
                'failed_redirects' => 0,
                'unique_ips' => [],
                'by_country' => [],
                'by_device' => [],
                'by_referer' => [],
                'avg_response_time' => 0,
                'total_response_time' => 0,
                'top_slugs' => [],
            ]);

            AppLogger::event('redirect', 'debug', 'redirect_metrics.current_hourly', [
                'total_redirects' => $hourMetrics['total_redirects'],
                'unique_ips_count' => count($hourMetrics['unique_ips']),
            ]);

            $hourMetrics['total_redirects']++;

            if ($metrics['success']) {
                $hourMetrics['successful_redirects']++;
            } else {
                $hourMetrics['failed_redirects']++;
            }

            // Dados únicos
            $hourMetrics['unique_ips'][$metrics['ip']] = true;

            // Agrupamentos para gráficos
            if ($metrics['country']) {
                $hourMetrics['by_country'][$metrics['country']] =
                    ($hourMetrics['by_country'][$metrics['country']] ?? 0) + 1;
            }

            if ($metrics['device']) {
                $hourMetrics['by_device'][$metrics['device']] =
                    ($hourMetrics['by_device'][$metrics['device']] ?? 0) + 1;
            }

            // Referer para análise de tráfego
            $refererDomain = $this->extractDomain($metrics['referer']);
            $hourMetrics['by_referer'][$refererDomain] =
                ($hourMetrics['by_referer'][$refererDomain] ?? 0) + 1;

            // Slugs mais acessados
            $hourMetrics['top_slugs'][$metrics['slug']] =
                ($hourMetrics['top_slugs'][$metrics['slug']] ?? 0) + 1;

            // Tempo de resposta
            $hourMetrics['total_response_time'] += $metrics['response_time'];
            $hourMetrics['avg_response_time'] =
                $hourMetrics['total_response_time'] / $hourMetrics['total_redirects'];

            AppLogger::event('redirect', 'debug', 'redirect_metrics.saving_hourly', [
                'key' => $hourKey,
                'total_redirects' => $hourMetrics['total_redirects'],
                'successful' => $hourMetrics['successful_redirects'],
                'failed' => $hourMetrics['failed_redirects'],
            ]);

            Cache::put($hourKey, $hourMetrics, 3600); // 1 hora

            // Métricas diárias agregadas
            $dayKey = "redirect_metrics:day:{$day}";
            AppLogger::event('redirect', 'debug', 'redirect_metrics.processing_daily', ['key' => $dayKey]);

            $dayMetrics = Cache::get($dayKey, [
                'total_redirects' => 0,
                'unique_ips_count' => 0,
                'top_countries' => [],
                'top_devices' => [],
                'top_referers' => [],
                'top_slugs' => [],
                'hourly_distribution' => [],
            ]);

            $dayMetrics['total_redirects']++;
            $dayMetrics['unique_ips_count'] = count($hourMetrics['unique_ips']);

            // Distribuição por hora do dia
            $currentHour = (int) now()->format('H');
            $dayMetrics['hourly_distribution'][$currentHour] =
                ($dayMetrics['hourly_distribution'][$currentHour] ?? 0) + 1;

            // Agregar tops diários
            if ($metrics['country']) {
                $dayMetrics['top_countries'][$metrics['country']] =
                    ($dayMetrics['top_countries'][$metrics['country']] ?? 0) + 1;
            }

            if ($metrics['device']) {
                $dayMetrics['top_devices'][$metrics['device']] =
                    ($dayMetrics['top_devices'][$metrics['device']] ?? 0) + 1;
            }

            $dayMetrics['top_referers'][$refererDomain] =
                ($dayMetrics['top_referers'][$refererDomain] ?? 0) + 1;

            $dayMetrics['top_slugs'][$metrics['slug']] =
                ($dayMetrics['top_slugs'][$metrics['slug']] ?? 0) + 1;

            Cache::put($dayKey, $dayMetrics, 86400); // 24 horas

        } catch (\Exception $e) {
            AppLogger::event('redirect', 'debug', 'redirect_metrics.collect_failed', [
                'error' => $e->getMessage(),
                'metrics' => $metrics,
            ]);
        }
    }

    /**
     * Extrai país do IP
     */
    private function getCountryFromIp(?string $ip): ?string
    {
        AppLogger::event('redirect', 'debug', 'redirect_metrics.geoip_lookup', ['ip' => $ip]);

        if (! $ip || in_array($ip, ['127.0.0.1', '::1'])) {
            AppLogger::event('redirect', 'debug', 'redirect_metrics.geoip_localhost', []);

            return 'localhost';
        }

        try {
            // Verificar cache primeiro
            $cacheKey = "geoip:country:{$ip}";
            $cachedCountry = Cache::get($cacheKey);

            if ($cachedCountry !== null) {
                AppLogger::event('redirect', 'debug', 'redirect_metrics.geoip_cache_hit', ['country' => $cachedCountry]);

                return $cachedCountry;
            }

            AppLogger::event('redirect', 'debug', 'redirect_metrics.geoip_attempt', []);

            // Usar o serviço GeoIP do Laravel (torann/geoip)
            $geoip = app('geoip');
            $location = $geoip->getLocation($ip);

            // Verificar se a localização foi encontrada (não é default)
            if (! $location->default && $location->country) {
                AppLogger::event('redirect', 'debug', 'redirect_metrics.geoip_found', ['country' => $location->country]);
                // Cache por 24 horas
                Cache::put($cacheKey, $location->country, 86400);

                return $location->country;
            }

            AppLogger::event('redirect', 'debug', 'redirect_metrics.geoip_default', []);

            // Cache null result por 1 hora para evitar lookups repetidos
            Cache::put($cacheKey, null, 3600);

        } catch (\Exception $e) {
            AppLogger::event('redirect', 'debug', 'redirect_metrics.geoip_failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return null;
    }

    /**
     * Detecta tipo de dispositivo
     */
    private function getDeviceType(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        if (preg_match('/(ipad|tablet|android(?!.*mobile))/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/(mobile|phone|iphone|ipod|blackberry|iemobile|opera mini)/i', $userAgent)) {
            return 'mobile';
        }

        if (preg_match('/(bot|crawler|spider|scraper)/i', $userAgent)) {
            return 'bot';
        }

        return 'desktop';
    }

    /**
     * Extrai domínio do referer
     */
    private function extractDomain(?string $referer): string
    {
        if (! $referer || $referer === '-') {
            return 'Direct';
        }

        $domain = parse_url($referer, PHP_URL_HOST);

        return $domain ?: 'Unknown';
    }

    /**
     * Verifica se o Cache está disponível
     */
    private function isCacheAvailable(): bool
    {
        try {
            $testKey = 'cache_test_redirect_'.uniqid();
            AppLogger::event('redirect', 'debug', 'redirect_metrics.cache_test', ['test_key' => $testKey]);

            // Testar operação de cache simples
            Cache::put($testKey, 'test', 1);

            // Verificar se consegue ler
            $testValue = Cache::get($testKey);

            if ($testValue === 'test') {
                AppLogger::event('redirect', 'debug', 'redirect_metrics.cache_available', []);
                // Limpar teste
                Cache::forget($testKey);

                return true;
            } else {
                AppLogger::event('redirect', 'debug', 'redirect_metrics.cache_read_failed', []);

                return false;
            }
        } catch (\Exception $e) {
            AppLogger::event('redirect', 'debug', 'redirect_metrics.cache_unavailable', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * 🌐 CAPTURA IP REAL DO USUÁRIO (MESMA LÓGICA DO LinkTrackingService)
     *
     * Delega no ClientIpResolver compartilhado. O log de origem virou um evento único
     * porque a origem também é única: antes existiam cinco eventos, um por posição da
     * cadeia de precedência que foi removida.
     *
     * @param  Request  $request  A requisição HTTP atual.
     * @return string Um endereço IP válido.
     */
    private function getRealUserIP(Request $request): string
    {
        $ip = $this->clientIpResolver->fromRequest($request);

        AppLogger::event('redirect', 'debug', 'redirect_metrics.ip_resolved', ['ip' => $ip]);

        return $ip;
    }
}
