#!/bin/bash

# ========================================================
# SCRIPT DE DEBUG AUTOMÁTICO PARA PRODUÇÃO - LINK CHART
# ========================================================

SERVER="138.197.121.81"
PROJECT_PATH="/var/www/linkchartapi"

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Função para logs coloridos
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_debug() {
    echo -e "${PURPLE}[DEBUG]${NC} $1"
}

echo "🔍 INICIANDO DIAGNÓSTICO AUTOMÁTICO DE PRODUÇÃO"
echo "=============================================="
echo "Servidor: $SERVER"
echo "Data: $(date)"
echo ""

# =====================================
# FASE 1: VERIFICAÇÃO DE CONTAINERS
# =====================================
log_info "FASE 1: Verificando status dos containers..."

CONTAINERS=("linkchartapi" "linkchartdb" "linkchartredis" "linkchartnginx")
ALL_RUNNING=true

for container in "${CONTAINERS[@]}"; do
    echo -n "🔍 Verificando $container: "
    if ssh root@$SERVER "docker ps --filter \"name=$container\" --filter \"status=running\" --format '{{.Names}}'" | grep -q "$container"; then
        log_success "RODANDO"
    else
        log_error "NÃO RODANDO"
        ALL_RUNNING=false
    fi
done

if [ "$ALL_RUNNING" = false ]; then
    log_error "❌ Nem todos os containers estão rodando. Verifique o docker-compose."
    echo "   Comando: ssh root@$SERVER \"cd $PROJECT_PATH && docker-compose -f docker-compose.prod.yml ps\""
    exit 1
else
    log_success "✅ Todos os containers estão rodando"
fi
echo ""

# =====================================
# FASE 2: VERIFICAÇÃO DE ARQUIVOS .ENV
# =====================================
log_info "FASE 2: Verificando arquivos de ambiente..."

echo -n "🔍 Verificando .env no host: "
if ssh root@$SERVER "test -f $PROJECT_PATH/.env"; then
    log_success "EXISTS"
else
    log_error "NOT FOUND"
    echo "   Comando para corrigir: ssh root@$SERVER \"cd $PROJECT_PATH && cp .env.production .env\""
    exit 1
fi

echo -n "🔍 Verificando .env no container: "
if ssh root@$SERVER "docker exec linkchartapi test -f /var/www/.env"; then
    log_success "MOUNTED"
else
    log_error "NOT MOUNTED"
    echo "   Problema no volume mount do docker-compose"
    exit 1
fi

echo -n "🔍 Comparando .env vs .env.production: "
DIFF_OUTPUT=$(ssh root@$SERVER "cd $PROJECT_PATH && diff .env .env.production" 2>/dev/null)
if [ $? -eq 0 ]; then
    log_success "IDENTICAL"
else
    log_warning "DIFFERENT"
    echo "   Diferenças encontradas. Verificar se .env foi copiado corretamente."
fi
echo ""

# =====================================
# FASE 3: VERIFICAÇÃO DE CONFIGURAÇÕES CRÍTICAS
# =====================================
log_info "FASE 3: Verificando configurações críticas do Laravel..."

# APP_ENV
echo -n "🔍 APP_ENV: "
APP_ENV=$(ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:show app.env 2>/dev/null | grep -v Warning | grep -v 'Module.*already loaded'")
if [[ "$APP_ENV" == *"production"* ]]; then
    log_success "$APP_ENV"
else
    log_error "$APP_ENV"
fi

# APP_DEBUG
echo -n "🔍 APP_DEBUG: "
APP_DEBUG=$(ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:show app.debug 2>/dev/null | grep -v Warning | grep -v 'Module.*already loaded'")
if [[ "$APP_DEBUG" == *"false"* ]]; then
    log_success "$APP_DEBUG"
else
    log_warning "$APP_DEBUG (deveria ser false em produção)"
fi

# LOG_CHANNEL
echo -n "🔍 LOG_CHANNEL: "
LOG_CHANNEL=$(ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:show logging.default 2>/dev/null | grep -v Warning | grep -v 'Module.*already loaded'")
echo "$LOG_CHANNEL"

# Verificar se o canal de log existe
echo -n "🔍 Verificando se canal de log existe: "
if ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:show logging.channels.$LOG_CHANNEL 2>/dev/null | grep -v Warning | grep -v 'Module.*already loaded'" >/dev/null; then
    log_success "CHANNEL EXISTS"
else
    log_error "CHANNEL NOT FOUND"
    log_warning "Este pode ser o problema principal! LOG_CHANNEL=$LOG_CHANNEL não existe."
    echo "   Sugestão: Alterar LOG_CHANNEL para 'daily' temporariamente"
    echo "   Comando: ssh root@$SERVER \"cd $PROJECT_PATH && sed -i 's/LOG_CHANNEL=.*/LOG_CHANNEL=daily/' .env\""

    # Perguntar se quer corrigir automaticamente
    read -p "🔧 Quer corrigir automaticamente? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        log_info "Corrigindo LOG_CHANNEL..."
        ssh root@$SERVER "cd $PROJECT_PATH && sed -i 's/LOG_CHANNEL=.*/LOG_CHANNEL=daily/' .env"
        ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:clear"
        ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:cache"
        log_success "LOG_CHANNEL corrigido para 'daily'"
    fi
fi
echo ""

# =====================================
# FASE 4: VERIFICAÇÃO JWT
# =====================================
log_info "FASE 4: Verificando configuração JWT..."

echo -n "🔍 JWT_SECRET no arquivo .env: "
JWT_FILE=$(ssh root@$SERVER "cd $PROJECT_PATH && grep 'JWT_SECRET=' .env | head -c 50")
if [[ "$JWT_FILE" == *"SPF"* ]]; then
    log_success "CORRECT (${JWT_FILE}...)"
else
    log_error "INCORRECT ($JWT_FILE)"
fi

echo -n "🔍 JWT_SECRET carregado pelo Laravel: "
JWT_CONFIG=$(ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:show jwt.secret 2>/dev/null | grep -v Warning | grep -v 'Module.*already loaded' | head -c 50")
if [[ "$JWT_CONFIG" == *"SPF"* ]]; then
    log_success "LOADED (${JWT_CONFIG}...)"
else
    log_error "NOT LOADED ($JWT_CONFIG)"
    log_warning "JWT não está sendo carregado corretamente!"
fi
echo ""

# =====================================
# FASE 5: VERIFICAÇÃO DE CONEXÕES
# =====================================
log_info "FASE 5: Testando conexões..."

echo -n "🔍 Conexão PostgreSQL: "
DB_TEST=$(ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan tinker --execute=\"try { DB::connection()->getPdo(); echo 'OK'; } catch(Exception \\\$e) { echo 'ERROR: ' . \\\$e->getMessage(); }\" 2>/dev/null | grep -v Warning | grep -v 'Module.*already loaded' | tail -1")
if [[ "$DB_TEST" == "OK" ]]; then
    log_success "$DB_TEST"
else
    log_error "$DB_TEST"
fi

echo -n "🔍 Conexão Redis: "
REDIS_TEST=$(ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan tinker --execute=\"try { Cache::store('redis')->put('test', 'ok'); echo 'OK'; } catch(Exception \\\$e) { echo 'ERROR: ' . \\\$e->getMessage(); }\" 2>/dev/null | grep -v Warning | grep -v 'Module.*already loaded' | tail -1")
if [[ "$REDIS_TEST" == "OK" ]]; then
    log_success "$REDIS_TEST"
else
    log_error "$REDIS_TEST"
fi
echo ""

# =====================================
# FASE 6: ANÁLISE DE LOGS
# =====================================
log_info "FASE 6: Analisando logs de erro..."

CURRENT_DATE=$(date +%Y-%m-%d)
LOG_FILE="/var/www/storage/logs/laravel-$CURRENT_DATE.log"

echo "🔍 Últimos erros encontrados:"
ERRORS=$(ssh root@$SERVER "docker exec linkchartapi tail -50 $LOG_FILE 2>/dev/null | grep -i error | tail -5" || echo "Nenhum log encontrado para hoje")
if [ -n "$ERRORS" ] && [ "$ERRORS" != "Nenhum log encontrado para hoje" ]; then
    echo "$ERRORS"
else
    log_info "✅ Nenhum erro recente encontrado nos logs"
fi
echo ""

# =====================================
# FASE 7: TESTE DE ENDPOINTS
# =====================================
log_info "FASE 7: Testando endpoints..."

echo -n "🔍 Health Check: "
HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://$SERVER/health)
if [ "$HEALTH_STATUS" = "200" ]; then
    log_success "OK ($HEALTH_STATUS)"
else
    log_error "FAILED ($HEALTH_STATUS)"
fi

echo -n "🔍 API Endpoint (auth/register): "
API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST http://$SERVER/api/auth/register \
    -H "Content-Type: application/json" \
    -d '{"name":"Test","email":"test@example.com","password":"password123","password_confirmation":"password123"}')
if [ "$API_STATUS" = "200" ] || [ "$API_STATUS" = "201" ]; then
    log_success "OK ($API_STATUS)"
elif [ "$API_STATUS" = "422" ]; then
    log_warning "VALIDATION ERROR ($API_STATUS) - endpoint funciona mas dados inválidos"
else
    log_error "FAILED ($API_STATUS)"
fi
echo ""

# =====================================
# RESUMO E RECOMENDAÇÕES
# =====================================
echo "📋 RESUMO DO DIAGNÓSTICO"
echo "======================="

if [ "$ALL_RUNNING" = true ] && [[ "$JWT_CONFIG" == *"SPF"* ]] && [ "$HEALTH_STATUS" = "200" ]; then
    log_success "✅ Sistema aparenta estar funcionando corretamente"
else
    log_error "❌ Problemas identificados que precisam ser corrigidos:"

    if [ "$ALL_RUNNING" = false ]; then
        echo "   • Containers não estão todos rodando"
    fi

    if [[ "$JWT_CONFIG" != *"SPF"* ]]; then
        echo "   • JWT_SECRET não está sendo carregado corretamente"
    fi

    if [ "$HEALTH_STATUS" != "200" ]; then
        echo "   • Health check falhando"
    fi

    if [[ "$LOG_CHANNEL" != "daily" ]] && ! ssh root@$SERVER "docker exec linkchartapi php /var/www/artisan config:show logging.channels.$LOG_CHANNEL 2>/dev/null" >/dev/null; then
        echo "   • LOG_CHANNEL configurado para canal que não existe"
    fi
fi

echo ""
echo "🔧 COMANDOS ÚTEIS PARA CORREÇÃO:"
echo "==============================="
echo "• Corrigir LOG_CHANNEL:"
echo "  ssh root@$SERVER \"cd $PROJECT_PATH && sed -i 's/LOG_CHANNEL=.*/LOG_CHANNEL=daily/' .env\""
echo ""
echo "• Limpar caches:"
echo "  ssh root@$SERVER \"docker exec linkchartapi php /var/www/artisan config:clear\""
echo "  ssh root@$SERVER \"docker exec linkchartapi php /var/www/artisan config:cache\""
echo ""
echo "• Ver logs em tempo real:"
echo "  ssh root@$SERVER \"docker exec linkchartapi tail -f $LOG_FILE\""
echo ""
echo "• Reiniciar container da aplicação:"
echo "  ssh root@$SERVER \"cd $PROJECT_PATH && docker-compose -f docker-compose.prod.yml restart app\""
echo ""

log_info "🎯 Diagnóstico concluído!"
