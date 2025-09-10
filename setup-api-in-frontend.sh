#!/bin/bash

# Script para adicionar configuração da API no container do frontend
echo "🔧 Configurando api.linkcharts.com.br no container do frontend..."

# 1. Copiar configuração para o container do frontend
echo "📋 Copiando configuração para o container..."
docker cp api-proxy-config.conf linkcharts-frontend-prod:/etc/nginx/conf.d/api-proxy.conf

# 2. Testar configuração do Nginx no container
echo "🧪 Testando configuração do Nginx..."
docker exec linkcharts-frontend-prod nginx -t

if [ $? -eq 0 ]; then
    echo "✅ Configuração do Nginx válida"

    # 3. Recarregar Nginx no container
    echo "🔄 Recarregando Nginx no container..."
    docker exec linkcharts-frontend-prod nginx -s reload

    echo "🎉 API proxy configurado com sucesso!"
    echo ""
    echo "📊 Agora api.linkcharts.com.br deve funcionar:"
    echo "  🌐 https://api.linkcharts.com.br/health"
    echo "  📡 https://api.linkcharts.com.br/api"
    echo ""
    echo "🔍 Para testar:"
    echo "  curl -I https://api.linkcharts.com.br/health"

else
    echo "❌ Erro na configuração do Nginx"
    echo "🔧 Verifique os logs: docker exec linkcharts-frontend-prod nginx -t"
    exit 1
fi
