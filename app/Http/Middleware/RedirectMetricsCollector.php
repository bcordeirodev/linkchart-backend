<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware específico para coletar métricas detalhadas de redirecionamentos
 * Usado APENAS no endpoint público /r/{slug}
 */
class RedirectMetricsCollector
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $slug = $request->route('slug');
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $referer = $request->headers->get('referer');

        Log::info('RedirectMetricsCollector: Starting', [
            'slug' => $slug,
            'ip' => $ip,
            'user_agent' => substr($userAgent, 0, 100) // Truncar para log
        ]);

        // Processar redirecionamento
        $response = $next($request);

        $responseTime = microtime(true) - $startTime;
        $statusCode = $response->getStatusCode();

        Log::info('RedirectMetricsCollector: Response processed', [
            'slug' => $slug,
            'status_code' => $statusCode,
            'response_time' => $responseTime
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

            Log::info('RedirectMetricsCollector: Metrics collected successfully', ['slug' => $slug]);
        } catch (\Exception $e) {
            Log::error('RedirectMetricsCollector: Failed to collect metrics', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            // Não falhar a requisição por causa das métricas
        }

        return $response;
    }

    /**
     * Coleta métricas específicas de redirecionamento
     */
    private function collectRedirectMetrics(array $metrics): void
    {
        Log::info('RedirectMetricsCollector: collectRedirectMetrics called', [
            'slug' => $metrics['slug'] ?? 'unknown'
        ]);

        try {
            // Verificar se Cache está disponível
            if (!$this->isCacheAvailable()) {
                Log::warning('RedirectMetricsCollector: Cache not available, skipping metrics collection');
                return;
            }

            Log::info('RedirectMetricsCollector: Cache available, proceeding with metrics');

            $hour = now()->format('Y-m-d-H');
            $day = now()->format('Y-m-d');

            Log::info('RedirectMetricsCollector: Time variables set', [
                'hour' => $hour,
                'day' => $day
            ]);

            // Métricas por hora de redirecionamentos
            $hourKey = "redirect_metrics:hour:{$hour}";
            Log::info('RedirectMetricsCollector: Getting hourly metrics', ['key' => $hourKey]);
            
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
            
            Log::info('RedirectMetricsCollector: Current hourly metrics', [
                'total_redirects' => $hourMetrics['total_redirects'],
                'unique_ips_count' => count($hourMetrics['unique_ips'])
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

            Log::info('RedirectMetricsCollector: Saving hourly metrics', [
                'key' => $hourKey,
                'total_redirects' => $hourMetrics['total_redirects'],
                'successful' => $hourMetrics['successful_redirects'],
                'failed' => $hourMetrics['failed_redirects']
            ]);
            
            Cache::put($hourKey, $hourMetrics, 3600); // 1 hora

            // Métricas diárias agregadas
            $dayKey = "redirect_metrics:day:{$day}";
            Log::info('RedirectMetricsCollector: Processing daily metrics', ['key' => $dayKey]);
            
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
            $currentHour = (int)now()->format('H');
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

            // Log estruturado para análise posterior (usando canal padrão)
            Log::info('redirect_metrics', [
                'slug' => $metrics['slug'],
                'ip' => $metrics['ip'],
                'country' => $metrics['country'],
                'device' => $metrics['device'],
                'referer_domain' => $refererDomain,
                'response_time' => $metrics['response_time'],
                'success' => $metrics['success'],
                'timestamp' => $metrics['timestamp']->toISOString(),
                'utm_source' => $metrics['utm_params']['utm_source'] ?? null,
                'utm_medium' => $metrics['utm_params']['utm_medium'] ?? null,
                'utm_campaign' => $metrics['utm_params']['utm_campaign'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to collect redirect metrics', [
                'error' => $e->getMessage(),
                'metrics' => $metrics
            ]);
        }
    }

    /**
     * Extrai país do IP
     */
    private function getCountryFromIp(?string $ip): ?string
    {
        Log::info('RedirectMetricsCollector: Getting country for IP', ['ip' => $ip]);
        
        if (!$ip || in_array($ip, ['127.0.0.1', '::1'])) {
            Log::info('RedirectMetricsCollector: Localhost IP detected');
            return 'localhost';
        }

        try {
            // Verificar cache primeiro
            $cacheKey = "geoip:country:{$ip}";
            $cachedCountry = Cache::get($cacheKey);
            
            if ($cachedCountry !== null) {
                Log::info('RedirectMetricsCollector: Country found in cache', ['country' => $cachedCountry]);
                return $cachedCountry;
            }
            
            Log::info('RedirectMetricsCollector: Attempting GeoIP lookup');
            
            if (function_exists('geoip')) {
                $location = geoip($ip);
                $country = $location->getAttribute('country');
                
                if ($country) {
                    Log::info('RedirectMetricsCollector: Country found via GeoIP', ['country' => $country]);
                    // Cache por 24 horas
                    Cache::put($cacheKey, $country, 86400);
                    return $country;
                }
            }
            
            Log::info('RedirectMetricsCollector: No GeoIP function or no country found');
            
            // Cache null result por 1 hora para evitar lookups repetidos
            Cache::put($cacheKey, null, 3600);
            
        } catch (\Exception $e) {
            Log::error('RedirectMetricsCollector: GeoIP lookup failed', [
                'ip' => $ip, 
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }

        return null;
    }

    /**
     * Detecta tipo de dispositivo
     */
    private function getDeviceType(?string $userAgent): string
    {
        if (!$userAgent) {
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
        if (!$referer || $referer === '-') {
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
            $testKey = 'cache_test_redirect_' . uniqid();
            Log::info('RedirectMetricsCollector: Testing cache availability', ['test_key' => $testKey]);
            
            // Testar operação de cache simples
            Cache::put($testKey, 'test', 1);
            
            // Verificar se consegue ler
            $testValue = Cache::get($testKey);
            
            if ($testValue === 'test') {
                Log::info('RedirectMetricsCollector: Cache is available and working');
                // Limpar teste
                Cache::forget($testKey);
                return true;
            } else {
                Log::warning('RedirectMetricsCollector: Cache write succeeded but read failed');
                return false;
            }
        } catch (\Exception $e) {
            Log::error('RedirectMetricsCollector: Cache not available', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }
}
