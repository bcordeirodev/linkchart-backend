#!/bin/bash

echo "🚨 DEBUG CRÍTICO - ERRO 404 PERSISTENTE"
echo "======================================="

# Função para execução SSH
ssh_exec() {
    ssh -o StrictHostKeyChecking=no root@138.197.121.81 "$1" 2>/dev/null
}

echo "📦 1. STATUS DOS CONTAINERS:"
ssh_exec "cd /var/www/linkchartapi && docker compose -f docker-compose.prod.yml ps"

echo ""
echo "🔍 2. TESTANDO ROTA HEALTH DIRETAMENTE NO LARAVEL:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi php /var/www/artisan route:list | grep health"

echo ""
echo "🌐 3. TESTANDO HEALTH CHECK INTERNO (dentro do container):"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi curl -v http://localhost/health"

echo ""
echo "📋 4. TESTANDO PHP-FPM DIRETAMENTE:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi curl -v http://localhost:9000/health"

echo ""
echo "🔧 5. VERIFICANDO CONFIGURAÇÃO NGINX:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartnginx cat /etc/nginx/conf.d/default.conf | grep -A 10 -B 5 'location /'"

echo ""
echo "📂 6. VERIFICANDO ESTRUTURA DE ARQUIVOS:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi ls -la /var/www/public/"

echo ""
echo "🧪 7. TESTANDO INDEX.PHP DIRETAMENTE:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi curl -v http://localhost/index.php"

echo ""
echo "🔍 8. LOGS DO NGINX:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartnginx tail -10 /var/log/nginx/error.log"

echo ""
echo "🔍 9. LOGS DO PHP-FPM:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi tail -10 /var/log/php-fpm.log || echo 'Log PHP-FPM não encontrado'"

echo ""
echo "⚙️ 10. VERIFICANDO PROCESSOS NO CONTAINER:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi ps aux | grep -E 'nginx|php'"

echo ""
echo "🌍 11. TESTANDO DIRETAMENTE DO HOST:"
echo "Teste externo (host -> nginx):"
curl -v http://138.197.121.81/health 2>&1 | head -20

echo ""
echo "🔧 12. VERIFICANDO COMUNICAÇÃO NGINX-PHP:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartnginx ping -c 1 linkchartapi || echo 'Ping falhou'"

echo ""
echo "📝 13. TESTANDO ARTISAN TINKER:"
ssh_exec "cd /var/www/linkchartapi && docker exec linkchartapi php /var/www/artisan tinker --execute=\"echo 'Laravel OK: ' . app()->version();\""
