#!/bin/bash

# ==============================================
# SCRIPT DE DEPLOY PARA PRODUÇÃO - DIGITALOCEAN
# ==============================================

set -e

echo "🚀 Iniciando deploy para produção..."

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configurações
SERVER_IP="138.197.121.81"
SERVER_USER="root"
PROJECT_PATH="/var/www/linkchartapi"

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

# Verificar se estamos no diretório correto
if [ ! -f "docker-compose.prod.yml" ]; then
    log_error "docker-compose.prod.yml não encontrado. Execute este script do diretório back-end/"
    exit 1
fi

# Verificar se .env.production existe
if [ ! -f ".env.production" ]; then
    log_error ".env.production não encontrado!"
    exit 1
fi

log_info "Fazendo push das alterações para o repositório..."
git add .
git commit -m "Deploy: $(date '+%Y-%m-%d %H:%M:%S')" || log_warning "Nenhuma alteração para commit"
git push origin main

log_info "Conectando ao servidor e executando deploy..."

ssh $SERVER_USER@$SERVER_IP << 'EOF'
    set -e
    
    echo "📂 Navegando para o diretório do projeto..."
    cd /var/www/linkchartapi
    
    echo "📥 Baixando últimas alterações..."
    git pull origin main
    
    echo "📋 Copiando arquivo de produção..."
    cp .env.production .env
    
    echo "🐳 Parando containers..."
    docker compose -f docker-compose.prod.yml down
    
    echo "🔨 Rebuilding e iniciando containers..."
    docker compose -f docker-compose.prod.yml up -d --build
    
    echo "⏳ Aguardando containers iniciarem..."
    sleep 30
    
    echo "🗄️ Executando migrações..."
    docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
    
    echo "🧹 Limpando cache..."
    docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache
    docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache
    docker compose -f docker-compose.prod.yml exec -T app php artisan view:cache
    
    echo "🔍 Verificando status dos containers..."
    docker compose -f docker-compose.prod.yml ps
    
    echo "🧪 Testando aplicação..."
    curl -f http://localhost/up || echo "⚠️ Teste de saúde falhou"
    
    echo "✅ Deploy concluído!"
    
EOF

log_success "Deploy finalizado! Testando acesso externo..."

# Teste final
sleep 5
if curl -f "http://$SERVER_IP/up" > /dev/null 2>&1; then
    log_success "✅ Aplicação está respondendo em http://$SERVER_IP"
else
    log_warning "⚠️ Aplicação pode não estar respondendo. Verifique os logs no servidor."
fi

echo ""
log_info "🔗 Acesse: http://$SERVER_IP"
log_info "📊 Logs: ssh $SERVER_USER@$SERVER_IP 'docker logs linkchartapi'"
echo ""