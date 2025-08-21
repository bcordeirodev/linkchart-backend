#!/bin/bash

# ===========================================
# CORREÇÃO DEFINITIVA DA ARQUITETURA DE DEPLOY
# ===========================================
# Este script aplica as correções necessárias para resolver 
# o conflito entre Dockerfile e docker-compose em produção

set -e

echo "🔧 APLICANDO CORREÇÃO DEFINITIVA DE ARQUITETURA"
echo "==============================================="
echo "Data: $(date)"
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
}

log_info() {
    echo -e "${BLUE}ℹ️ $1${NC}"
}

echo "🔍 FASE 1: PARANDO CONTAINERS ATUAIS"
echo "===================================="

log_info "Parando todos os containers..."
docker compose -f docker-compose.prod.yml down || true

echo ""
echo "🧹 FASE 2: LIMPANDO AMBIENTE"
echo "============================"

log_info "Removendo imagens antigas..."
docker rmi linkchartapi-app || true

log_info "Limpando volumes órfãos..."
docker volume prune -f || true

echo ""
echo "🔨 FASE 3: REBUILD COMPLETO"
echo "==========================="

log_info "Fazendo backup do .env atual..."
cp .env .env.backup.$(date +%Y%m%d_%H%M%S) || true

log_info "Copiando configuração de produção..."
cp .env.production .env

log_info "Configurando JWT_SECRET..."
if ! grep -q "^JWT_SECRET=" .env; then
    echo "JWT_SECRET=base64:$(openssl rand -base64 64)" >> .env
    log_success "JWT_SECRET gerado automaticamente"
else
    log_success "JWT_SECRET já configurado"
fi

log_info "Construindo nova imagem..."
docker build -t linkchartapi-app .

echo ""
echo "🚀 FASE 4: DEPLOY COM NOVA ARQUITETURA"
echo "====================================="

log_info "Subindo containers com arquitetura corrigida..."
docker compose -f docker-compose.prod.yml up -d

log_info "Aguardando containers ficarem prontos..."
sleep 10

echo ""
echo "⚡ FASE 5: OTIMIZAÇÕES LARAVEL"
echo "============================="

log_info "Limpando cache..."
docker exec linkchartapi php artisan config:clear || true
docker exec linkchartapi php artisan cache:clear || true

log_info "Otimizando para produção..."
docker exec linkchartapi php artisan config:cache
docker exec linkchartapi php artisan route:cache

log_info "Testando configuração JWT..."
JWT_LENGTH=$(docker exec linkchartapi php artisan tinker --execute="echo strlen(config('jwt.secret'));" 2>/dev/null | tail -1 | tr -d '\r\n')
if [ "$JWT_LENGTH" -gt 32 ]; then
    log_success "JWT_SECRET carregado corretamente no Laravel (length: $JWT_LENGTH)"
else
    log_error "JWT_SECRET não configurado corretamente"
fi

echo ""
echo "🔗 FASE 6: TESTES DE CONECTIVIDADE"
echo "================================="

log_info "Testando banco de dados..."
if docker exec linkchartapi php artisan tinker --execute="DB::connection()->getPdo(); echo 'Database OK';" 2>/dev/null | grep -q "Database OK"; then
    log_success "Database OK"
else
    log_error "Database connection failed"
fi

log_info "Testando Redis..."
if docker exec linkchartapi php artisan tinker --execute="Cache::store('redis')->put('test', 'ok', 60); echo Cache::store('redis')->get('test');" 2>/dev/null | grep -q "ok"; then
    log_success "Redis OK"
else
    log_error "Redis connection failed"
fi

echo ""
echo "🏥 FASE 7: HEALTH CHECK FINAL"
echo "============================="

log_info "Aguardando aplicação inicializar..."
sleep 5

log_info "Testando health check..."
HEALTH_RESPONSE=$(curl -s -w "%{http_code}" -o /tmp/health_response.txt http://138.197.121.81/health 2>/dev/null || echo "000")

if [ "$HEALTH_RESPONSE" == "200" ]; then
    log_success "Health Check: HTTP $HEALTH_RESPONSE (SUCESSO!)"
    echo ""
    echo -e "${GREEN}🎉 DEPLOY REALIZADO COM SUCESSO!${NC}"
    echo "✅ Aplicação está funcionando corretamente"
    echo "🌐 URL: http://138.197.121.81"
    echo "🏥 Health: http://138.197.121.81/health"
else
    log_error "Health Check: HTTP $HEALTH_RESPONSE (FALHA)"
    if [ -f /tmp/health_response.txt ]; then
        log_info "Resposta: $(cat /tmp/health_response.txt)"
    fi
    
    echo ""
    echo -e "${RED}❌ DEPLOY FALHOU - COLETANDO LOGS PARA DEBUG${NC}"
    echo ""
    echo "=== LOGS DO LINKCHARTAPI ==="
    docker logs linkchartapi --tail 20
    echo ""
    echo "=== LOGS DO NGINX ==="
    docker logs linkchartnginx --tail 20
    echo ""
    echo "=== STATUS DOS CONTAINERS ==="
    docker ps
    
    exit 1
fi

echo ""
echo "📊 FASE 8: INFORMAÇÕES FINAIS"
echo "============================="

log_info "Status dos containers:"
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo ""
log_info "Para monitorar logs em tempo real:"
echo "docker logs -f linkchartapi"
echo "docker logs -f linkchartnginx"

echo ""
log_success "Script de correção executado com sucesso!"
