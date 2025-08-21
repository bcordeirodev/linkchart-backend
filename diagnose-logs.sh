#!/bin/bash

# ===========================================
# SCRIPT DE DIAGNÓSTICO DE LOGS - LINK CHART
# ===========================================

echo "🔍 DIAGNÓSTICO DE LOGS - LINK CHART API"
echo "========================================"
echo "Data: $(date)"
echo ""

# Verificar se estamos no diretório correto
if [ ! -f "artisan" ]; then
    echo "❌ Execute este script do diretório back-end/"
    exit 1
fi

# 1. STATUS DOS CONTAINERS
echo "📦 STATUS DOS CONTAINERS:"
echo "------------------------"
docker ps --filter "name=linkchartapi" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
docker ps --filter "name=linkchartdb" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
docker ps --filter "name=linkchartredis" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo ""

# 2. VERIFICAR LOGS DISPONÍVEIS
echo "📄 ARQUIVOS DE LOG DISPONÍVEIS:"
echo "------------------------------"
if docker exec linkchartapi test -d /var/www/storage/logs; then
    docker exec linkchartapi ls -la /var/www/storage/logs/
else
    echo "❌ Diretório de logs não encontrado no container"
fi
echo ""

# 3. ÚLTIMOS ERROS DO LARAVEL
echo "🚨 ÚLTIMOS ERROS DO LARAVEL (20 linhas):"
echo "---------------------------------------"
CURRENT_DATE=$(date +%Y-%m-%d)
LOG_FILE="/var/www/storage/logs/laravel-$CURRENT_DATE.log"

if docker exec linkchartapi test -f "$LOG_FILE"; then
    docker exec linkchartapi tail -20 "$LOG_FILE" | grep -i error || echo "✅ Nenhum erro encontrado nos últimos registros"
else
    echo "⚠️ Arquivo de log de hoje não encontrado: $LOG_FILE"
    echo "Tentando arquivo laravel.log..."
    if docker exec linkchartapi test -f "/var/www/storage/logs/laravel.log"; then
        docker exec linkchartapi tail -20 /var/www/storage/logs/laravel.log | grep -i error || echo "✅ Nenhum erro encontrado"
    else
        echo "❌ Nenhum arquivo de log encontrado"
    fi
fi
echo ""

# 4. ÚLTIMOS ERROS DE API
echo "🔧 ÚLTIMOS ERROS DE API (10 linhas):"
echo "-----------------------------------"
API_LOG_FILE="/var/www/storage/logs/api-errors-$CURRENT_DATE.log"

if docker exec linkchartapi test -f "$API_LOG_FILE"; then
    docker exec linkchartapi tail -10 "$API_LOG_FILE" || echo "✅ Nenhum erro de API registrado"
else
    echo "✅ Arquivo de erros de API não existe (nenhum erro registrado)"
fi
echo ""

# 5. VERIFICAR CONFIGURAÇÕES CRÍTICAS
echo "⚙️ CONFIGURAÇÕES CRÍTICAS:"
echo "-------------------------"
echo "APP_ENV: $(docker exec linkchartapi php /var/www/artisan config:show app.env 2>/dev/null | grep -v 'Warning')"
echo "APP_DEBUG: $(docker exec linkchartapi php /var/www/artisan config:show app.debug 2>/dev/null | grep -v 'Warning')"
echo "LOG_CHANNEL: $(docker exec linkchartapi php /var/www/artisan config:show logging.default 2>/dev/null | grep -v 'Warning')"
echo "JWT_SECRET: $(docker exec linkchartapi php /var/www/artisan config:show jwt.secret 2>/dev/null | grep -v 'Warning' | head -c 30)..."
echo ""

# 6. TESTAR CONEXÕES
echo "🔗 TESTE DE CONEXÕES:"
echo "--------------------"
echo -n "Database: "
docker exec linkchartapi php /var/www/artisan tinker --execute="try { DB::connection()->getPdo(); echo 'OK'; } catch(Exception \$e) { echo 'ERROR: ' . \$e->getMessage(); }" 2>/dev/null | grep -v 'Warning'

echo -n "Redis: "
docker exec linkchartapi php /var/www/artisan tinker --execute="try { Cache::store('redis')->put('test', 'ok'); echo 'OK'; } catch(Exception \$e) { echo 'ERROR: ' . \$e->getMessage(); }" 2>/dev/null | grep -v 'Warning'
echo ""

# 7. ESPAÇO EM DISCO
echo "💾 ESPAÇO EM DISCO:"
echo "------------------"
docker exec linkchartapi df -h /var/www/storage/logs | tail -1
echo ""

# 8. SUGESTÕES
echo "💡 SUGESTÕES DE COMANDOS ÚTEIS:"
echo "------------------------------"
echo "• Ver logs em tempo real:"
echo "  docker exec linkchartapi tail -f /var/www/storage/logs/laravel-$(date +%Y-%m-%d).log"
echo ""
echo "• Testar endpoint de diagnóstico (requer autenticação):"
echo "  curl http://138.197.121.81/api/logs/diagnostic -H 'Authorization: Bearer TOKEN'"
echo ""
echo "• Limpar caches se necessário:"
echo "  docker exec linkchartapi php /var/www/artisan config:clear"
echo "  docker exec linkchartapi php /var/www/artisan cache:clear"
echo ""

echo "✅ Diagnóstico concluído!"
