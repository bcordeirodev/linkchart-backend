<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;
use Carbon\Carbon;

class LogController extends Controller
{
    /**
     * Listar arquivos de log disponíveis
     */
    public function listLogs()
    {
        try {
            $logPath = storage_path('logs');

            if (!File::exists($logPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Diretório de logs não encontrado',
                    'logs' => []
                ]);
            }

            $files = File::files($logPath);
            $logs = [];

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $size = $file->getSize();
                $modified = Carbon::createFromTimestamp($file->getMTime());

                $logs[] = [
                    'filename' => $filename,
                    'size' => $this->formatBytes($size),
                    'size_bytes' => $size,
                    'modified' => $modified->format('Y-m-d H:i:s'),
                    'modified_ago' => $modified->diffForHumans(),
                    'path' => $file->getPathname()
                ];
            }

            // Ordenar por data de modificação (mais recente primeiro)
            usort($logs, function($a, $b) {
                return $b['size_bytes'] <=> $a['size_bytes'];
            });

            return response()->json([
                'success' => true,
                'logs' => $logs,
                'total_files' => count($logs)
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar logs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar logs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ler conteúdo de um arquivo de log específico
     */
    public function readLog(Request $request, $filename)
    {
        try {
            $logPath = storage_path('logs/' . $filename);

            if (!File::exists($logPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo de log não encontrado'
                ], 404);
            }

            $lines = (int) $request->get('lines', 100);
            $search = $request->get('search', '');

            $content = File::get($logPath);
            $logLines = explode("\n", $content);

            // Filtrar por busca se especificado
            if (!empty($search)) {
                $logLines = array_filter($logLines, function($line) use ($search) {
                    return stripos($line, $search) !== false;
                });
            }

            // Pegar as últimas N linhas
            $logLines = array_slice($logLines, -$lines);

            return response()->json([
                'success' => true,
                'filename' => $filename,
                'lines_returned' => count($logLines),
                'search_term' => $search,
                'content' => array_reverse($logLines) // Mais recente primeiro
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao ler log', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao ler log: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter logs de erro mais recentes
     */
    public function getRecentErrors(Request $request)
    {
        try {
            $hours = (int) $request->get('hours', 24);
            $level = $request->get('level', 'error');

            $logFiles = [
                'laravel-' . date('Y-m-d') . '.log',
                'api-errors-' . date('Y-m-d') . '.log',
                'laravel.log'
            ];

            $errors = [];

            foreach ($logFiles as $logFile) {
                $logPath = storage_path('logs/' . $logFile);

                if (File::exists($logPath)) {
                    $content = File::get($logPath);
                    $lines = explode("\n", $content);

                    foreach ($lines as $line) {
                        if (stripos($line, $level) !== false) {
                            // Extrair timestamp se presente
                            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                                $timestamp = Carbon::parse($matches[1]);
                                if ($timestamp->gt(Carbon::now()->subHours($hours))) {
                                    $errors[] = [
                                        'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                                        'file' => $logFile,
                                        'line' => $line
                                    ];
                                }
                            } else {
                                // Se não tem timestamp, assume como recente
                                $errors[] = [
                                    'timestamp' => 'Unknown',
                                    'file' => $logFile,
                                    'line' => $line
                                ];
                            }
                        }
                    }
                }
            }

            // Ordenar por timestamp (mais recente primeiro)
            usort($errors, function($a, $b) {
                if ($a['timestamp'] === 'Unknown') return 1;
                if ($b['timestamp'] === 'Unknown') return -1;
                return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
            });

            return response()->json([
                'success' => true,
                'hours_searched' => $hours,
                'level_searched' => $level,
                'errors_found' => count($errors),
                'errors' => array_slice($errors, 0, 50) // Limitar a 50 erros
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar erros recentes', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar erros: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Diagnóstico completo do sistema
     */
    public function systemDiagnostic()
    {
        try {
            $diagnostic = [
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'environment' => [
                    'app_env' => config('app.env'),
                    'app_debug' => config('app.debug'),
                    'log_channel' => config('logging.default'),
                    'log_level' => config('logging.channels.daily.level', 'N/A')
                ],
                'database' => $this->checkDatabase(),
                'redis' => $this->checkRedis(),
                'jwt' => $this->checkJWT(),
                'storage' => $this->checkStorage(),
                'recent_errors' => $this->getLastErrors(10)
            ];

            return response()->json([
                'success' => true,
                'diagnostic' => $diagnostic
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro no diagnóstico: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Testar sistema de logs
     */
    public function testLogging()
    {
        try {
            // Testar diferentes níveis de log
            Log::info('Teste de log INFO - Sistema funcionando');
            Log::warning('Teste de log WARNING - Aviso de teste');
            Log::error('Teste de log ERROR - Erro simulado para teste');

            // Testar log em canal específico
            Log::channel('api_errors')->error('Teste de log no canal api_errors');

            return response()->json([
                'success' => true,
                'message' => 'Logs de teste criados com sucesso',
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao testar logs: ' . $e->getMessage()
            ], 500);
        }
    }

    // Métodos auxiliares privados

    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function checkDatabase()
    {
        try {
            \DB::connection()->getPdo();
            return ['status' => 'OK', 'message' => 'Conexão com banco estabelecida'];
        } catch (\Exception $e) {
            return ['status' => 'ERROR', 'message' => $e->getMessage()];
        }
    }

    private function checkRedis()
    {
        try {
            \Cache::store('redis')->put('test', 'ok', 60);
            return ['status' => 'OK', 'message' => 'Conexão com Redis estabelecida'];
        } catch (\Exception $e) {
            return ['status' => 'ERROR', 'message' => $e->getMessage()];
        }
    }

    private function checkJWT()
    {
        try {
            $secret = config('jwt.secret');
            if (empty($secret) || $secret === 'YOUR_JWT_SECRET_HERE_GENERATE_WITH_ARTISAN_JWT_SECRET') {
                return ['status' => 'ERROR', 'message' => 'JWT_SECRET não configurado'];
            }
            return ['status' => 'OK', 'message' => 'JWT configurado', 'length' => strlen($secret)];
        } catch (\Exception $e) {
            return ['status' => 'ERROR', 'message' => $e->getMessage()];
        }
    }

    private function checkStorage()
    {
        try {
            $logPath = storage_path('logs');
            $writable = is_writable($logPath);
            $space = disk_free_space($logPath);

            return [
                'status' => $writable ? 'OK' : 'ERROR',
                'writable' => $writable,
                'free_space' => $this->formatBytes($space),
                'path' => $logPath
            ];
        } catch (\Exception $e) {
            return ['status' => 'ERROR', 'message' => $e->getMessage()];
        }
    }

    private function getLastErrors($count = 5)
    {
        try {
            $logFile = storage_path('logs/laravel-' . date('Y-m-d') . '.log');

            if (!File::exists($logFile)) {
                return [];
            }

            $content = File::get($logFile);
            $lines = explode("\n", $content);
            $errors = [];

            foreach (array_reverse($lines) as $line) {
                if (stripos($line, 'ERROR') !== false && count($errors) < $count) {
                    $errors[] = $line;
                }
            }

            return $errors;
        } catch (\Exception $e) {
            return ['Error reading logs: ' . $e->getMessage()];
        }
    }
}
