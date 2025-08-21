#!/bin/bash

echo "🚨 CORREÇÃO URGENTE - PRODUÇÃO LINK CHART"
echo "========================================"

# Conectar no servidor e corrigir problemas
ssh -o StrictHostKeyChecking=no root@138.197.121.81 << 'ENDSSH'
    echo "🔍 Verificando estrutura atual..."

    # Verificar diretório correto
    if [ -d "/var/www/linkchartapi" ]; then
        cd /var/www/linkchartapi
        echo "✅ Diretório existe: /var/www/linkchartapi"
    elif [ -d "/root/linkchartapi" ]; then
        cd /root/linkchartapi
        echo "✅ Diretório existe: /root/linkchartapi"
    else
        echo "❌ Diretório do projeto não encontrado!"
        ls -la /var/www/
        ls -la /root/
        exit 1
    fi

    echo "📂 Conteúdo do diretório atual:"
    ls -la

    echo ""
    echo "🔧 CORREÇÃO 1: Configurar APP_DEBUG=false"
    # Backup do .env atual
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)

    # Corrigir APP_DEBUG
    if grep -q "APP_DEBUG=true" .env; then
        sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env
        echo "✅ APP_DEBUG corrigido para false"
    else
        echo "ℹ️ APP_DEBUG já está false ou não encontrado"
    fi

    echo ""
    echo "🔧 CORREÇÃO 2: Verificar e limpar caches"

    # Verificar se containers estão rodando
    echo "📦 Status dos containers:"
    docker ps --filter "name=linkchartapi" --format "table {{.Names}}\t{{.Status}}"

    # Limpar caches Laravel
    if docker exec linkchartapi test -f /var/www/artisan; then
        echo "🧹 Limpando caches..."
        docker exec linkchartapi php /var/www/artisan config:clear
        docker exec linkchartapi php /var/www/artisan cache:clear
        docker exec linkchartapi php /var/www/artisan route:clear
        docker exec linkchartapi php /var/www/artisan view:clear
        echo "✅ Caches limpos"

        echo "🔄 Recriando caches..."
        docker exec linkchartapi php /var/www/artisan config:cache
        docker exec linkchartapi php /var/www/artisan route:cache
        echo "✅ Caches recriados"
    else
        echo "❌ Artisan não encontrado no container"
    fi

    echo ""
    echo "🔧 CORREÇÃO 3: Verificar logs de erro"
    echo "📋 Últimos erros Laravel:"

    # Verificar logs mais recentes
    LATEST_LOG=$(docker exec linkchartapi find /var/www/storage/logs -name "*.log" -type f -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -1 | cut -d' ' -f2)
    if [ ! -z "$LATEST_LOG" ]; then
        echo "📄 Log mais recente: $LATEST_LOG"
        docker exec linkchartapi tail -30 "$LATEST_LOG" | grep -A 5 -B 5 "ERROR\|CRITICAL\|ParseError\|FatalError" || echo "✅ Nenhum erro crítico nos logs recentes"
    else
        echo "⚠️ Nenhum arquivo de log encontrado"
    fi

    echo ""
    echo "🔧 CORREÇÃO 4: Restart completo"
    echo "🛑 Parando containers..."
    docker compose -f docker-compose.prod.yml down

    echo "🚀 Iniciando containers..."
    docker compose -f docker-compose.prod.yml up -d

    echo "⏳ Aguardando containers ficarem prontos..."
    sleep 15

    echo "🔍 Status final dos containers:"
    docker compose -f docker-compose.prod.yml ps

    echo ""
    echo "🧪 TESTE FINAL"
    echo "🏥 Health check:"
    curl -f http://138.197.121.81/health && echo "✅ Health check OK" || echo "❌ Health check failed"

    echo "🔐 Teste registro (deve retornar erro 422 por dados duplicados, não 500):"
    curl -X POST http://138.197.121.81/api/auth/register \
      -H "Content-Type: application/json" \
      -d '{"name":"Test User","email":"test@example.com","password":"password123","password_confirmation":"password123"}' \
      -s -w "\nHTTP Status: %{http_code}\n"

ENDSSH

echo ""
echo "✅ CORREÇÕES APLICADAS!"
echo "🔍 Execute novamente o debug para verificar:"
echo "   ./debug-production-errors.sh"
