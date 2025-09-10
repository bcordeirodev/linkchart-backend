#!/bin/bash

# Script para configurar proxy reverso para api.linkcharts.com.br
# Execute este script no servidor de produção

echo "🔧 Configurando proxy reverso para api.linkcharts.com.br..."

# 1. Copiar configuração do Nginx
echo "📋 Copiando configuração do Nginx..."
sudo cp nginx-api-config.conf /etc/nginx/sites-available/api-linkcharts

# 2. Habilitar o site
echo "✅ Habilitando site api-linkcharts..."
sudo ln -sf /etc/nginx/sites-available/api-linkcharts /etc/nginx/sites-enabled/api-linkcharts

# 3. Testar configuração do Nginx
echo "🧪 Testando configuração do Nginx..."
sudo nginx -t

if [ $? -eq 0 ]; then
    echo "✅ Configuração do Nginx válida"

    # 4. Recarregar Nginx
    echo "🔄 Recarregando Nginx..."
    sudo systemctl reload nginx

    echo "🎉 Proxy reverso configurado com sucesso!"
    echo ""
    echo "📊 Agora api.linkcharts.com.br deve funcionar:"
    echo "  🌐 https://api.linkcharts.com.br/health"
    echo "  📡 https://api.linkcharts.com.br/api"
    echo ""
    echo "🔍 Para testar:"
    echo "  curl -I https://api.linkcharts.com.br/health"

else
    echo "❌ Erro na configuração do Nginx"
    echo "🔧 Verifique os logs: sudo nginx -t"
    exit 1
fi
