#!/bin/bash

# ===========================================
# TESTE COMPLETO DE PRODUÇÃO - LINK CHART
# ===========================================
# Este script testa ABSOLUTAMENTE TUDO que pode dar erro em produção

set -e  # Para no primeiro erro

echo "🧪 TESTE COMPLETO DE PRODUÇÃO - LINK CHART"
echo "==========================================="
echo "Data: $(date)"
echo "Servidor: 138.197.121.81"
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

SUCCESS_COUNT=0
ERROR_COUNT=0
WARNING_COUNT=0

# Função para logs coloridos
log_success() {
    echo -e "${GREEN}✅ $1${NC}"
    ((SUCCESS_COUNT++))
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
    ((ERROR_COUNT++))
}

log_warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
    ((WARNING_COUNT++))
}

log_info() {
    echo -e "${BLUE}ℹ️ $1${NC}"
}

# Função para executar comandos SSH
ssh_exec() {
    ssh -o StrictHostKeyChecking=no root@138.197.121.81 "$1" 2>/dev/null
}

# Função para executar comandos Docker
docker_exec() {
    ssh_exec "docker exec linkchartapi $1" 2>/dev/null
}

echo "🔍 FASE 1: VERIFICAÇÃO DE INFRAESTRUTURA"
echo "========================================"

# 1.1 - Conectividade SSH
echo "📡 1.1 - Testando conectividade SSH..."
if ssh_exec "echo 'SSH OK'" | grep -q "SSH OK"; then
    log_success "SSH: Conexão estabelecida"
else
    log_error "SSH: Falha na conexão"
    exit 1
fi

# 1.2 - Status dos Containers
echo ""
echo "🐳 1.2 - Verificando status dos containers..."
CONTAINERS=("linkchartapi" "linkchartdb" "linkchartredis")
for container in "${CONTAINERS[@]}"; do
    if ssh_exec "docker ps --filter \"name=$container\" --filter \"status=running\" | grep -q $container"; then
        log_success "Container $container: RUNNING"
    else
        log_error "Container $container: NOT RUNNING"
    fi
done

# 1.3 - Recursos do Sistema
echo ""
echo "💾 1.3 - Verificando recursos do sistema..."
DISK_USAGE=$(ssh_exec "df -h / | tail -1 | awk '{print \$5}' | sed 's/%//'")
if [ "$DISK_USAGE" -lt 90 ]; then
    log_success "Disco: ${DISK_USAGE}% usado (OK)"
else
    log_warning "Disco: ${DISK_USAGE}% usado (ALTO)"
fi

MEMORY_USAGE=$(ssh_exec "free | grep Mem | awk '{printf \"%.1f\", \$3/\$2 * 100.0}'")
log_info "Memória: ${MEMORY_USAGE}% usado"

echo ""
echo "🔍 FASE 2: VERIFICAÇÃO DE AMBIENTE E CONFIGURAÇÕES"
echo "=================================================="

# 2.1 - Variáveis de Ambiente Críticas
echo "⚙️ 2.1 - Verificando variáveis de ambiente críticas..."
ENV_VARS=("APP_ENV" "APP_DEBUG" "APP_KEY" "JWT_SECRET" "DB_CONNECTION" "REDIS_HOST")
for var in "${ENV_VARS[@]}"; do
    VALUE=$(docker_exec "grep \"^$var=\" /var/www/.env | cut -d'=' -f2- | head -c 20")
    if [ ! -z "$VALUE" ] && [ "$VALUE" != "null" ]; then
        if [ "$var" == "APP_DEBUG" ]; then
            if [ "$VALUE" == "false" ]; then
                log_success "$var: $VALUE (Produção OK)"
            else
                log_error "$var: $VALUE (DEVE SER false EM PRODUÇÃO!)"
            fi
        elif [ "$var" == "APP_ENV" ]; then
            if [ "$VALUE" == "production" ]; then
                log_success "$var: $VALUE (OK)"
            else
                log_warning "$var: $VALUE (Esperado: production)"
            fi
        else
            log_success "$var: Configurado (${VALUE}...)"
        fi
    else
        log_error "$var: NÃO CONFIGURADO"
    fi
done

# 2.2 - Configurações Laravel
echo ""
echo "🔧 2.2 - Verificando configurações Laravel..."
CONFIG_CHECKS=(
    "app.env:production"
    "app.debug:false"
    "database.default:pgsql"
    "cache.default:redis"
    "session.driver:redis"
    "queue.default:redis"
)

for check in "${CONFIG_CHECKS[@]}"; do
    CONFIG_KEY=$(echo $check | cut -d':' -f1)
    EXPECTED=$(echo $check | cut -d':' -f2)

    ACTUAL=$(docker_exec "php /var/www/artisan tinker --execute=\"echo config('$CONFIG_KEY');\"" 2>/dev/null | tail -1 | tr -d '\r\n')

    if [ "$ACTUAL" == "$EXPECTED" ]; then
        log_success "Config $CONFIG_KEY: $ACTUAL (OK)"
    else
        log_warning "Config $CONFIG_KEY: $ACTUAL (Esperado: $EXPECTED)"
    fi
done

echo ""
echo "🔍 FASE 3: VERIFICAÇÃO DE BANCO DE DADOS"
echo "======================================="

# 3.1 - Conexão com PostgreSQL
echo "🗄️ 3.1 - Testando conexão com PostgreSQL..."
DB_TEST=$(docker_exec "php /var/www/artisan tinker --execute=\"try { DB::connection()->getPdo(); echo 'DB_CONNECTED'; } catch(Exception \$e) { echo 'DB_ERROR:' . \$e->getMessage(); }\"" 2>/dev/null | tail -1)

if echo "$DB_TEST" | grep -q "DB_CONNECTED"; then
    log_success "PostgreSQL: Conexão OK"
else
    log_error "PostgreSQL: $DB_TEST"
fi

# 3.2 - Verificar Tabelas Essenciais
echo ""
echo "📋 3.2 - Verificando tabelas essenciais..."
TABLES=("users" "links" "clicks" "migrations")
for table in "${TABLES[@]}"; do
    COUNT=$(docker_exec "php /var/www/artisan tinker --execute=\"try { echo DB::table('$table')->count(); } catch(Exception \$e) { echo 'ERROR'; }\"" 2>/dev/null | tail -1)

    if [ "$COUNT" != "ERROR" ] && [ ! -z "$COUNT" ]; then
        log_success "Tabela $table: $COUNT registros"
    else
        log_error "Tabela $table: Não encontrada ou erro"
    fi
done

# 3.3 - Verificar Migrações
echo ""
echo "🔄 3.3 - Verificando status das migrações..."
MIGRATION_STATUS=$(docker_exec "php /var/www/artisan migrate:status | grep -c 'Ran'" 2>/dev/null || echo "0")
PENDING_MIGRATIONS=$(docker_exec "php /var/www/artisan migrate:status | grep -c 'Pending'" 2>/dev/null || echo "0")

if [ "$MIGRATION_STATUS" -gt 0 ] && [ "$PENDING_MIGRATIONS" -eq 0 ]; then
    log_success "Migrações: $MIGRATION_STATUS executadas, $PENDING_MIGRATIONS pendentes"
else
    log_warning "Migrações: $MIGRATION_STATUS executadas, $PENDING_MIGRATIONS pendentes"
fi

echo ""
echo "🔍 FASE 4: VERIFICAÇÃO DE REDIS E CACHE"
echo "======================================"

# 4.1 - Conexão com Redis
echo "🔴 4.1 - Testando conexão com Redis..."
REDIS_TEST=$(docker_exec "php /var/www/artisan tinker --execute=\"try { Cache::store('redis')->put('test_connection', 'ok', 60); echo Cache::store('redis')->get('test_connection'); } catch(Exception \$e) { echo 'REDIS_ERROR:' . \$e->getMessage(); }\"" 2>/dev/null | tail -1)

if echo "$REDIS_TEST" | grep -q "ok"; then
    log_success "Redis: Conexão e cache OK"
else
    log_error "Redis: $REDIS_TEST"
fi

# 4.2 - Teste de Performance Cache
echo ""
echo "⚡ 4.2 - Testando performance do cache..."
CACHE_START=$(date +%s%N)
docker_exec "php /var/www/artisan tinker --execute=\"Cache::put('perf_test', 'data_'.time(), 300);\"" >/dev/null 2>&1
CACHE_END=$(date +%s%N)
CACHE_TIME=$(( (CACHE_END - CACHE_START) / 1000000 ))

if [ $CACHE_TIME -lt 100 ]; then
    log_success "Cache Performance: ${CACHE_TIME}ms (Excelente)"
elif [ $CACHE_TIME -lt 500 ]; then
    log_success "Cache Performance: ${CACHE_TIME}ms (Bom)"
else
    log_warning "Cache Performance: ${CACHE_TIME}ms (Lento)"
fi

echo ""
echo "🔍 FASE 5: VERIFICAÇÃO DE STORAGE E PERMISSÕES"
echo "============================================="

# 5.1 - Estrutura de Diretórios
echo "📁 5.1 - Verificando estrutura de diretórios..."
DIRECTORIES=(
    "/var/www/storage/logs"
    "/var/www/storage/framework/cache"
    "/var/www/storage/framework/sessions"
    "/var/www/storage/framework/views"
    "/var/www/storage/app"
    "/var/www/bootstrap/cache"
)

for dir in "${DIRECTORIES[@]}"; do
    if docker_exec "test -d $dir && test -w $dir"; then
        log_success "Diretório $dir: Existe e é gravável"
    else
        log_error "Diretório $dir: Não existe ou sem permissão de escrita"
    fi
done

# 5.2 - Teste de Escrita
echo ""
echo "✍️ 5.2 - Testando escrita em storage..."
TEST_FILE="/var/www/storage/logs/test_write_$(date +%s).log"
if docker_exec "echo 'Test write' > $TEST_FILE && rm $TEST_FILE"; then
    log_success "Storage: Escrita OK"
else
    log_error "Storage: Falha na escrita"
fi

# 5.3 - Verificar Logs Existentes
echo ""
echo "📄 5.3 - Verificando logs existentes..."
LOG_COUNT=$(docker_exec "find /var/www/storage/logs -name '*.log' | wc -l" 2>/dev/null || echo "0")
if [ "$LOG_COUNT" -gt 0 ]; then
    log_success "Logs: $LOG_COUNT arquivos encontrados"

    # Verificar logs de erro recentes
    ERROR_LINES=$(docker_exec "find /var/www/storage/logs -name '*.log' -exec grep -l 'ERROR\\|CRITICAL\\|FATAL' {} \\;" 2>/dev/null | wc -l || echo "0")
    if [ "$ERROR_LINES" -eq 0 ]; then
        log_success "Logs: Nenhum erro crítico encontrado"
    else
        log_warning "Logs: $ERROR_LINES arquivos com erros encontrados"
    fi
else
    log_warning "Logs: Nenhum arquivo de log encontrado"
fi

echo ""
echo "🔍 FASE 6: VERIFICAÇÃO DE SUPERVISOR E QUEUES"
echo "============================================"

# 6.1 - Status do Supervisor
echo "👷 6.1 - Verificando status do Supervisor..."
SUPERVISOR_STATUS=$(docker_exec "supervisorctl status" 2>/dev/null || echo "ERROR")

if echo "$SUPERVISOR_STATUS" | grep -q "RUNNING"; then
    RUNNING_PROCS=$(echo "$SUPERVISOR_STATUS" | grep -c "RUNNING" || echo "0")
    log_success "Supervisor: $RUNNING_PROCS processos rodando"

    # Verificar processos específicos
    if echo "$SUPERVISOR_STATUS" | grep -q "nginx.*RUNNING"; then
        log_success "Nginx: RUNNING"
    else
        log_error "Nginx: NOT RUNNING"
    fi

    if echo "$SUPERVISOR_STATUS" | grep -q "php-fpm.*RUNNING"; then
        log_success "PHP-FPM: RUNNING"
    else
        log_error "PHP-FPM: NOT RUNNING"
    fi

    if echo "$SUPERVISOR_STATUS" | grep -q "laravel-worker.*RUNNING"; then
        log_success "Laravel Worker: RUNNING"
    else
        log_warning "Laravel Worker: NOT RUNNING"
    fi
else
    log_error "Supervisor: $SUPERVISOR_STATUS"
fi

# 6.2 - Teste de Queue
echo ""
echo "📬 6.2 - Testando sistema de filas..."
QUEUE_TEST=$(docker_exec "php /var/www/artisan tinker --execute=\"try { Queue::push(function() { /* test */ }); echo 'QUEUE_OK'; } catch(Exception \$e) { echo 'QUEUE_ERROR:' . \$e->getMessage(); }\"" 2>/dev/null | tail -1)

if echo "$QUEUE_TEST" | grep -q "QUEUE_OK"; then
    log_success "Queue: Sistema funcionando"
else
    log_warning "Queue: $QUEUE_TEST"
fi

echo ""
echo "🔍 FASE 7: VERIFICAÇÃO DE APIs E ENDPOINTS"
echo "========================================"

# 7.1 - Health Check
echo "🏥 7.1 - Testando Health Check..."
HEALTH_RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null http://138.197.121.81/health 2>/dev/null || echo "000")

if [ "$HEALTH_RESPONSE" == "200" ]; then
    log_success "Health Check: HTTP $HEALTH_RESPONSE (OK)"
else
    log_error "Health Check: HTTP $HEALTH_RESPONSE (FALHA)"
fi

# 7.2 - Teste de Registro (deve retornar erro de validação, não 500)
echo ""
echo "📝 7.2 - Testando endpoint de registro..."
REG_RESPONSE=$(curl -s -w "%{http_code}" -o /tmp/reg_test.json -X POST http://138.197.121.81/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"invalid-email","password":"123"}' 2>/dev/null || echo "000")

if [ "$REG_RESPONSE" == "422" ]; then
    log_success "Registro API: HTTP $REG_RESPONSE (Validação OK)"
elif [ "$REG_RESPONSE" == "500" ]; then
    log_error "Registro API: HTTP $REG_RESPONSE (ERRO INTERNO)"
    if [ -f /tmp/reg_test.json ]; then
        log_info "Resposta: $(cat /tmp/reg_test.json | head -100)"
    fi
else
    log_warning "Registro API: HTTP $REG_RESPONSE (Inesperado)"
fi

# 7.3 - Teste de Links (deve requerer autenticação)
echo ""
echo "🔗 7.3 - Testando endpoint de links..."
LINKS_RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null http://138.197.121.81/api/links 2>/dev/null || echo "000")

if [ "$LINKS_RESPONSE" == "401" ]; then
    log_success "Links API: HTTP $LINKS_RESPONSE (Autenticação requerida - OK)"
else
    log_warning "Links API: HTTP $LINKS_RESPONSE (Inesperado)"
fi

echo ""
echo "🔍 FASE 8: VERIFICAÇÃO DE SEGURANÇA"
echo "=================================="

# 8.1 - Verificar Headers de Segurança
echo "🛡️ 8.1 - Verificando headers de segurança..."
SECURITY_HEADERS=$(curl -s -I http://138.197.121.81/health 2>/dev/null)

if echo "$SECURITY_HEADERS" | grep -qi "X-Frame-Options"; then
    log_success "Security: X-Frame-Options presente"
else
    log_warning "Security: X-Frame-Options ausente"
fi

if echo "$SECURITY_HEADERS" | grep -qi "X-XSS-Protection"; then
    log_success "Security: X-XSS-Protection presente"
else
    log_warning "Security: X-XSS-Protection ausente"
fi

if echo "$SECURITY_HEADERS" | grep -qi "X-Content-Type-Options"; then
    log_success "Security: X-Content-Type-Options presente"
else
    log_warning "Security: X-Content-Type-Options ausente"
fi

# 8.2 - Verificar se informações sensíveis não estão expostas
echo ""
echo "🔒 8.2 - Verificando exposição de informações sensíveis..."
DEBUG_RESPONSE=$(curl -s http://138.197.121.81/api/nonexistent 2>/dev/null || echo "")

if echo "$DEBUG_RESPONSE" | grep -q "APP_DEBUG" || echo "$DEBUG_RESPONSE" | grep -q "stack trace"; then
    log_error "Security: Informações de debug expostas!"
else
    log_success "Security: Informações sensíveis protegidas"
fi

echo ""
echo "🔍 FASE 9: VERIFICAÇÃO DE PERFORMANCE"
echo "==================================="

# 9.1 - Tempo de Resposta
echo "⚡ 9.1 - Medindo tempo de resposta..."
RESPONSE_TIME=$(curl -s -w "%{time_total}" -o /dev/null http://138.197.121.81/health 2>/dev/null || echo "999")
RESPONSE_MS=$(echo "$RESPONSE_TIME * 1000" | bc 2>/dev/null || echo "999")

if (( $(echo "$RESPONSE_TIME < 0.5" | bc -l) )); then
    log_success "Performance: ${RESPONSE_MS}ms (Excelente)"
elif (( $(echo "$RESPONSE_TIME < 1.0" | bc -l) )); then
    log_success "Performance: ${RESPONSE_MS}ms (Bom)"
elif (( $(echo "$RESPONSE_TIME < 2.0" | bc -l) )); then
    log_warning "Performance: ${RESPONSE_MS}ms (Aceitável)"
else
    log_warning "Performance: ${RESPONSE_MS}ms (Lento)"
fi

# 9.2 - Verificar Tamanho da Resposta
echo ""
echo "📊 9.2 - Verificando otimização de resposta..."
RESPONSE_SIZE=$(curl -s -w "%{size_download}" -o /dev/null http://138.197.121.81/health 2>/dev/null || echo "0")

if [ "$RESPONSE_SIZE" -lt 5000 ]; then
    log_success "Response Size: ${RESPONSE_SIZE} bytes (Otimizado)"
else
    log_warning "Response Size: ${RESPONSE_SIZE} bytes (Grande)"
fi

echo ""
echo "🔍 FASE 10: LIMPEZA E VERIFICAÇÃO FINAL"
echo "====================================="

# 10.1 - Limpeza de arquivos temporários
echo "🧹 10.1 - Limpando arquivos temporários..."
if [ -f /tmp/reg_test.json ]; then
    rm -f /tmp/reg_test.json
    log_success "Cleanup: Arquivos temporários removidos"
fi

# 10.2 - Verificação de espaço após testes
echo ""
echo "💾 10.2 - Verificação final de recursos..."
FINAL_DISK=$(ssh_exec "df -h / | tail -1 | awk '{print \$5}' | sed 's/%//'")
log_info "Uso final do disco: ${FINAL_DISK}%"

echo ""
echo "🎯 RELATÓRIO FINAL"
echo "=================="
echo -e "${GREEN}✅ Sucessos: $SUCCESS_COUNT${NC}"
echo -e "${YELLOW}⚠️ Avisos: $WARNING_COUNT${NC}"
echo -e "${RED}❌ Erros: $ERROR_COUNT${NC}"
echo ""

# Determinar status geral
if [ $ERROR_COUNT -eq 0 ] && [ $WARNING_COUNT -lt 3 ]; then
    echo -e "${GREEN}🎉 SISTEMA PRONTO PARA PRODUÇÃO!${NC}"
    echo "✅ Todos os testes críticos passaram"
    exit 0
elif [ $ERROR_COUNT -eq 0 ]; then
    echo -e "${YELLOW}⚠️ SISTEMA FUNCIONAL COM AVISOS${NC}"
    echo "⚠️ Alguns avisos encontrados, mas não críticos"
    exit 0
else
    echo -e "${RED}❌ SISTEMA NÃO PRONTO PARA PRODUÇÃO${NC}"
    echo "❌ Erros críticos encontrados que precisam ser corrigidos"
    exit 1
fi
