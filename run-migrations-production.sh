#!/bin/bash

# Script para executar migrações em produção
# Uso: ./run-migrations-production.sh

set -e

DEPLOY_HOST="134.209.33.182"
PROJECT_PATH="/var/www/linkchartapi"

echo "🚀 Executando migrações em produção..."
echo "📍 Host: $DEPLOY_HOST"
echo "📂 Path: $PROJECT_PATH"
echo ""

# Conectar via SSH e executar migrações
ssh -o StrictHostKeyChecking=no root@$DEPLOY_HOST << 'ENDSSH'
    set -e

    echo "📂 Navegando para o diretório do projeto..."
    cd /var/www/linkchartapi

    echo "🔍 Verificando status dos containers..."
    docker compose -f docker-compose.prod.yml ps

    echo ""
    echo "📋 Verificando migrações pendentes..."
    docker exec linkchartapi php /var/www/artisan migrate:status

    echo ""
    echo "🔄 Executando migrações..."
    docker exec linkchartapi php /var/www/artisan migrate --force

    echo ""
    echo "✅ Verificando estrutura da tabela clicks..."
    docker exec linkchartdb psql -U linkchartuser -d linkchartdb -c "\d clicks" | head -20

    echo ""
    echo "🧹 Limpando cache..."
    docker exec linkchartapi php /var/www/artisan cache:clear
    docker exec linkchartapi php /var/www/artisan config:clear
    docker exec linkchartapi php /var/www/artisan view:clear

    echo ""
    echo "🏥 Testando health check..."
    curl -f -s --max-time 10 http://localhost:8000/health | head -5

    echo ""
    echo "✅ Migrações executadas com sucesso!"
ENDSSH

echo ""
echo "🎉 Script concluído!"
echo "🌐 Teste a aplicação em: https://api.linkcharts.com.br/health"
