<?php

echo "🔍 DIAGNÓSTICO DO BACKEND LARAVEL\n";
echo "================================\n\n";

// 1. Verificar versão do PHP
echo "📋 Versão do PHP: " . PHP_VERSION . "\n";

// 2. Verificar extensões necessárias
$required_extensions = ['pdo', 'pdo_pgsql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json'];
echo "\n🔧 Extensões PHP:\n";
foreach ($required_extensions as $ext) {
    $status = extension_loaded($ext) ? "✅" : "❌";
    echo "  $status $ext\n";
}

// 3. Verificar arquivo .env
echo "\n📄 Arquivo .env:\n";
if (file_exists('.env')) {
    echo "  ✅ .env existe\n";

    // Verificar variáveis críticas
    $env_content = file_get_contents('.env');
    $critical_vars = ['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE'];

    foreach ($critical_vars as $var) {
        if (strpos($env_content, $var . '=') !== false) {
            echo "  ✅ $var definido\n";
        } else {
            echo "  ❌ $var não encontrado\n";
        }
    }
} else {
    echo "  ❌ .env não encontrado\n";
}

// 4. Verificar permissões de diretórios
echo "\n📁 Permissões de diretórios:\n";
$dirs_to_check = ['storage', 'storage/logs', 'storage/framework', 'bootstrap/cache'];

foreach ($dirs_to_check as $dir) {
    if (is_dir($dir)) {
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        $writable = is_writable($dir) ? "✅" : "❌";
        echo "  $writable $dir ($perms)\n";
    } else {
        echo "  ❌ $dir não existe\n";
    }
}

// 5. Verificar se o Composer foi executado
echo "\n📦 Dependências:\n";
if (is_dir('vendor')) {
    echo "  ✅ vendor/ existe\n";
    if (file_exists('vendor/autoload.php')) {
        echo "  ✅ autoload.php existe\n";
    } else {
        echo "  ❌ autoload.php não encontrado\n";
    }
} else {
    echo "  ❌ vendor/ não existe - execute 'composer install'\n";
}

// 6. Verificar logs recentes
echo "\n📋 Logs recentes:\n";
if (file_exists('storage/logs/laravel.log')) {
    $log_content = file_get_contents('storage/logs/laravel.log');
    $lines = explode("\n", $log_content);
    $recent_errors = array_filter($lines, function($line) {
        return strpos($line, 'ERROR') !== false && strpos($line, date('Y-m-d')) !== false;
    });

    if (empty($recent_errors)) {
        echo "  ✅ Nenhum erro recente encontrado\n";
    } else {
        echo "  ⚠️  Erros encontrados hoje:\n";
        foreach (array_slice($recent_errors, -3) as $error) {
            echo "    " . substr($error, 0, 100) . "...\n";
        }
    }
} else {
    echo "  ⚠️  Log não encontrado\n";
}

// 7. Testar conexão com banco
echo "\n🗄️  Conexão com banco:\n";
try {
    require_once 'vendor/autoload.php';

    $app = require_once 'bootstrap/app.php';
    $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

    $pdo = new PDO(
        'pgsql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT') . ';dbname=' . env('DB_DATABASE'),
        env('DB_USERNAME'),
        env('DB_PASSWORD')
    );
    echo "  ✅ Conexão com PostgreSQL bem-sucedida\n";
} catch (Exception $e) {
    echo "  ❌ Erro na conexão: " . $e->getMessage() . "\n";
}

// 8. Verificar porta 8000
echo "\n🌐 Porta 8000:\n";
$socket = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 1);
if ($socket) {
    echo "  ⚠️  Porta 8000 já está em uso\n";
    fclose($socket);
} else {
    echo "  ✅ Porta 8000 disponível\n";
}

// 9. Verificar memória
echo "\n💾 Recursos do sistema:\n";
echo "  📊 Limite de memória: " . ini_get('memory_limit') . "\n";
echo "  ⏱️  Tempo limite: " . ini_get('max_execution_time') . "s\n";

echo "\n🎯 RECOMENDAÇÕES:\n";
echo "================\n";

// Verificar problemas comuns
$issues = [];

if (!extension_loaded('pdo_pgsql')) {
    $issues[] = "Instalar extensão pdo_pgsql: sudo apt-get install php-pgsql";
}

if (!is_writable('storage')) {
    $issues[] = "Corrigir permissões: chmod -R 775 storage bootstrap/cache";
}

if (!file_exists('vendor/autoload.php')) {
    $issues[] = "Instalar dependências: composer install";
}

if (empty($issues)) {
    echo "✅ Nenhum problema crítico encontrado!\n";
    echo "💡 Se o servidor ainda para, tente:\n";
    echo "   - php artisan config:clear\n";
    echo "   - php artisan cache:clear\n";
    echo "   - ./start-server.sh (script com auto-restart)\n";
} else {
    foreach ($issues as $issue) {
        echo "⚠️  $issue\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Diagnóstico concluído em " . date('Y-m-d H:i:s') . "\n";
