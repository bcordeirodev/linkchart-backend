<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        // `?:` e não o default de env() de propósito: um `LOG_DEPRECATIONS_CHANNEL=null`
        // no .env é convertido pelo dotenv em **PHP null**, e PHP null faz o Laravel
        // usar o canal DEFAULT — o oposto de "descartar". Foi o que aconteceu em
        // produção: 80 deprecations do symfony/console por dia (vindas de invocações
        // de artisan) caíam no `stack`, iam para o OTLP e representavam 82% de tudo
        // no canal de warning, afogando os warnings reais da aplicação.
        //
        // Vão para um arquivo próprio, e NÃO para o OTLP: ficam consultáveis em
        // storage/logs/deprecations-*.log sem poluir o Grafana. Guardado por
        // tests/Feature/Logging/DeprecationRoutingTest.php.
        'channel' => env('LOG_DEPRECATIONS_CHANNEL') ?: 'deprecations_file',
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'app')),
            'ignore_exceptions' => false,
        ],

        // ===== Domain channels (each writes to its file + mirrors errors) =====

        'app' => [
            'driver' => 'stack',
            'channels' => ['app_file', 'errors'],
            'ignore_exceptions' => false,
        ],
        'app_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/app.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => env('LOG_APP_DAYS', 14),
            'tap' => [App\Logging\Taps\ChannelTap::class],
        ],

        'redirect' => [
            'driver' => 'stack',
            'channels' => ['redirect_file', 'errors'],
            'ignore_exceptions' => false,
        ],
        'redirect_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/redirect.log'),
            'level' => env('LOG_REDIRECT_LEVEL', 'info'),
            'days' => env('LOG_REDIRECT_DAYS', 7),
            'sample_rate' => env('LOG_REDIRECT_SAMPLE_RATE', 1.0),
            'tap' => [
                App\Logging\Taps\ChannelTap::class,
                App\Logging\Taps\SampleRateTap::class,
            ],
        ],

        'tracking' => [
            'driver' => 'stack',
            'channels' => ['tracking_file', 'errors'],
            'ignore_exceptions' => false,
        ],
        'tracking_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/tracking.log'),
            'level' => env('LOG_TRACKING_LEVEL', 'info'),
            'days' => env('LOG_TRACKING_DAYS', 14),
            'tap' => [App\Logging\Taps\ChannelTap::class],
        ],

        'jobs' => [
            'driver' => 'stack',
            'channels' => ['jobs_file', 'errors'],
            'ignore_exceptions' => false,
        ],
        'jobs_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/jobs.log'),
            'level' => env('LOG_JOBS_LEVEL', 'info'),
            'days' => env('LOG_JOBS_DAYS', 14),
            'tap' => [App\Logging\Taps\ChannelTap::class],
        ],

        // auth and audit channels skip PII redaction (compliance/incident response)
        'auth' => [
            'driver' => 'stack',
            'channels' => ['auth_file', 'errors'],
            'ignore_exceptions' => false,
        ],
        'auth_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/auth.log'),
            'level' => env('LOG_AUTH_LEVEL', 'info'),
            'days' => env('LOG_AUTH_DAYS', 4),
            'tap' => [App\Logging\Taps\ChannelTap::class.':skip-redaction'],
        ],

        'audit' => [
            'driver' => 'stack',
            'channels' => ['audit_file', 'errors'],
            'ignore_exceptions' => false,
        ],
        'audit_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/audit.log'),
            'level' => 'info',
            'days' => env('LOG_AUDIT_DAYS', 10),
            'tap' => [App\Logging\Taps\ChannelTap::class.':skip-redaction'],
        ],

        'http' => [
            'driver' => 'stack',
            'channels' => ['http_file', 'errors'],
            'ignore_exceptions' => false,
        ],
        'http_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/http.log'),
            'level' => env('LOG_HTTP_LEVEL', 'warning'),
            'days' => env('LOG_HTTP_DAYS', 14),
            'tap' => [App\Logging\Taps\ChannelTap::class],
        ],

        'errors' => [
            'driver' => 'stack',
            'channels' => ['errors_file', 'otlp'],
            // transport failures (e.g. Loki down) must never surface to callers
            'ignore_exceptions' => true,
        ],
        'errors_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/errors.log'),
            'level' => 'error',
            'days' => env('LOG_ERRORS_DAYS', 14),
            'tap' => [App\Logging\Taps\ChannelTap::class],
        ],

        // Forwards error-level records to Grafana Cloud Loki via OTLP/HTTP.
        // When OTEL_ENABLED is false (default) the channel attaches a NullHandler
        // so there is zero shipping and the test suite is not affected.
        'otlp' => [
            'driver' => 'custom',
            'via' => App\Logging\OtlpLogChannel::class,
            'level' => 'error',
        ],

        // ===== Standard channels (kept for compatibility/fallback) =====

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', \Monolog\Handler\SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [\Monolog\Processor\PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => \Monolog\Handler\StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [\Monolog\Processor\PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        // Deprecations do PHP e de dependências. Arquivo próprio, sem OTLP, retenção
        // curta — é informação de manutenção, não sinal operacional.
        'deprecations_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/deprecations.log'),
            'level' => 'debug',
            'days' => env('LOG_DEPRECATIONS_DAYS', 7),
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
