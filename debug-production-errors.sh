#!/bin/bash

echo "🔍 DEBUG PRODUÇÃO - ANÁLISE COMPLETA DE ERROS"
echo "=========================================="

echo "1. STATUS DOS CONTAINERS:"
ssh -o StrictHostKeyChecking=no root@138.197.121.81 "cd /var/www/linkchartapi && docker compose -f docker-compose.prod.yml ps"

echo ""
echo "2. LOGS RECENTES (últimas 20 linhas):"
ssh -o StrictHostKeyChecking=no root@138.197.121.81 "cd /var/www/linkchartapi && docker exec linkchartapi tail -20 /var/www/storage/logs/laravel-2025-08-21.log"

echo ""
echo "3. TESTE DE CONEXÃO COM BANCO:"
ssh -o StrictHostKeyChecking=no root@138.197.121.81 "cd /var/www/linkchartapi && docker exec linkchartapi php /var/www/artisan tinker --execute=\"
try {
    DB::connection()->getPdo();
    echo '✅ Database: Connected';
    echo 'Users: ' . DB::table('users')->count();
    echo 'Migrations: ' . DB::table('migrations')->count();
} catch(Exception \$e) {
    echo '❌ Database Error: ' . \$e->getMessage();
}
\""

echo ""
echo "4. CONFIGURAÇÕES CRÍTICAS:"
ssh -o StrictHostKeyChecking=no root@138.197.121.81 "cd /var/www/linkchartapi && docker exec linkchartapi php /var/www/artisan tinker --execute=\"
echo 'APP_ENV: ' . config('app.env');
echo 'APP_DEBUG: ' . (config('app.debug') ? 'true' : 'false');
echo 'LOG_LEVEL: ' . config('logging.channels.stack.level');
echo 'CACHE_DRIVER: ' . config('cache.default');
echo 'SESSION_DRIVER: ' . config('session.driver');
\""

echo ""
echo "5. TESTE ENDPOINT DE REGISTRO:"
curl -X POST http://138.197.121.81/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password123"}' \
  -v

echo ""
echo "6. VERIFICANDO ERROS NO SUPERVISOR/QUEUE:"
ssh -o StrictHostKeyChecking=no root@138.197.121.81 "cd /var/www/linkchartapi && docker exec linkchartapi supervisorctl status"
