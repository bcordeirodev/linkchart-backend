#!/bin/bash

echo "🔍 ANÁLISE RÁPIDA PRODUÇÃO - SUPER USER"
echo "======================================="

cd /var/www/linkchartapi

echo ""
echo "1. Verificando se Sanctum foi removido:"
docker exec linkchartapi composer show | grep sanctum || echo "✅ Sanctum não encontrado"

echo ""
echo "2. Verificando bootstrap/app.php (sem Sanctum):"
docker exec linkchartapi grep -A3 -B3 "Sanctum\|withProviders" /var/www/bootstrap/app.php || echo "✅ Sem referências ao Sanctum"

echo ""
echo "3. Testando JWT Auth diretamente no container:"
docker exec linkchartapi php /var/www/artisan tinker --execute="
try {
    echo 'JWT Secret length: ' . strlen(config('jwt.secret')) . PHP_EOL;
    echo 'JWT Class exists: ' . (class_exists('Tymon\\\\JWTAuth\\\\Facades\\\\JWTAuth') ? 'YES' : 'NO') . PHP_EOL;
    echo 'Auth guard api: ' . config('auth.guards.api.driver') . PHP_EOL;
} catch(Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage() . PHP_EOL;
}"

echo ""
echo "4. Testando AuthController diretamente:"
docker exec linkchartapi php /var/www/artisan tinker --execute="
try {
    \$controller = new App\\\\Http\\\\Controllers\\\\AuthController();
    echo 'AuthController: OK' . PHP_EOL;
} catch(Exception \$e) {
    echo 'AuthController ERROR: ' . \$e->getMessage() . PHP_EOL;
}"

echo ""
echo "5. Verificando se há erros nos logs atuais:"
docker exec linkchartapi tail -10 /var/www/storage/logs/laravel*.log | grep -i "error\|exception\|fatal" || echo "✅ Sem erros críticos"

echo ""
echo "6. Testando package discovery (pode ter problemas):"
docker exec linkchartapi php /var/www/artisan package:discover --ansi 2>&1 | tail -5

echo ""
echo "7. Verificando rotas de auth carregadas:"
docker exec linkchartapi php /var/www/artisan route:list --path=api/auth 2>&1 | head -5

echo ""
echo "✅ ANÁLISE CONCLUÍDA"
