#!/bin/bash

echo "🔍 ANÁLISE PRODUÇÃO - LINK CHART"
echo "==============================="
echo "Timestamp: $(date)"
echo ""

echo "📋 1. CONFIGURAÇÕES DE AMBIENTE"
echo "--------------------------------"
cd /var/www/linkchartapi

echo "JWT_SECRET:"
grep "JWT_SECRET=" .env | head -1

echo ""
echo "Database Config:"
grep "DB_" .env | head -5

echo ""
echo "App Config:"
grep "APP_" .env | head -5

echo ""
echo "📋 2. ARQUIVOS CRÍTICOS"
echo "------------------------"
echo "AuthController:"
ls -la /var/www/app/Http/Controllers/AuthController.php 2>/dev/null || echo "❌ AuthController não encontrado"

echo ""
echo "User Model:"
ls -la /var/www/app/Models/User.php 2>/dev/null || echo "❌ User Model não encontrado"

echo ""
echo "Routes API:"
ls -la /var/www/routes/api.php 2>/dev/null || echo "❌ Routes API não encontrado"

echo ""
echo "📋 3. DEPENDÊNCIAS"
echo "-------------------"
echo "Composer packages (JWT):"
cd /var/www && composer show tymon/jwt-auth 2>/dev/null | head -2 || echo "❌ JWT-AUTH não instalado"

echo ""
echo "📋 4. LOGS RECENTES"
echo "-------------------"
echo "Últimos erros:"
tail -10 /var/www/storage/logs/laravel-$(date +%Y-%m-%d).log 2>/dev/null || tail -10 /var/www/storage/logs/laravel.log 2>/dev/null || echo "❌ Nenhum log encontrado"

echo ""
echo "📋 5. CONFIGURAÇÃO LARAVEL"
echo "---------------------------"
echo "Config JWT carregada:"
cd /var/www && php artisan tinker --execute="echo 'JWT Secret Length: ' . strlen(config('jwt.secret')); echo PHP_EOL; echo 'JWT Algo: ' . config('jwt.algo');" 2>/dev/null || echo "❌ Erro ao verificar config JWT"

echo ""
echo "📋 6. DATABASE CONNECTION"
echo "-------------------------"
echo "Testando conexão DB:"
cd /var/www && php artisan tinker --execute="try { DB::connection()->getPdo(); echo 'Database: OK'; } catch(Exception \$e) { echo 'Database Error: ' . \$e->getMessage(); }" 2>/dev/null || echo "❌ Erro ao testar DB"

echo ""
echo "📋 7. CACHE STATUS"
echo "------------------"
echo "Cache config:"
cd /var/www && php artisan config:show cache 2>/dev/null | head -5 || echo "❌ Erro ao verificar cache"

echo ""
echo "📋 8. AUTOLOAD STATUS"
echo "---------------------"
echo "Composer autoload:"
cd /var/www && composer dump-autoload --optimize 2>/dev/null && echo "✅ Autoload OK" || echo "❌ Erro no autoload"

echo ""
echo "📋 9. CONTAINERS STATUS"
echo "-----------------------"
docker ps --filter "name=linkchartapi" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo ""
echo "🏁 ANÁLISE CONCLUÍDA"
echo "===================="
