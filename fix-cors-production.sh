#!/bin/bash

# ========================================
# 🔧 CORREÇÃO DE CORS - PRODUÇÃO
# ========================================

echo "🔧 Aplicando correções de CORS para produção..."
echo "==============================================="

# Verificar se estamos no diretório correto
if [ ! -f "composer.json" ]; then
    echo "❌ Erro: Execute este script no diretório do back-end"
    exit 1
fi

echo ""
echo "📋 1. Limpando cache do Laravel..."
echo "----------------------------------"

# Limpar cache do Laravel
docker-compose exec -T app php artisan config:clear
docker-compose exec -T app php artisan route:clear
docker-compose exec -T app php artisan cache:clear

echo ""
echo "📋 2. Recriando cache otimizado..."
echo "---------------------------------"

# Recriar cache
docker-compose exec -T app php artisan config:cache
docker-compose exec -T app php artisan route:cache

echo ""
echo "🔄 3. Reiniciando serviços..."
echo "----------------------------"

# Reiniciar containers
docker-compose restart app nginx

echo ""
echo "⏳ 4. Aguardando serviços ficarem prontos..."
echo "-------------------------------------------"

# Aguardar serviços
sleep 10

echo ""
echo "🧪 5. Testando CORS..."
echo "---------------------"

# Testar CORS
./test-cors.sh

echo ""
echo "📋 6. Verificando logs..."
echo "------------------------"

# Mostrar logs recentes
echo "Logs do Laravel (últimas 20 linhas):"
docker-compose logs --tail=20 app | grep -i cors || echo "Nenhum log de CORS encontrado"

echo ""
echo "✅ Correção de CORS aplicada!"
echo ""
echo "🔍 Próximos passos:"
echo "1. Verifique se o teste de CORS passou"
echo "2. Teste no navegador: https://linkcharts.com.br"
echo "3. Monitore os logs: docker-compose logs -f app"
echo ""
echo "📝 Se ainda houver problemas:"
echo "- Verifique se o Nginx foi reiniciado no servidor"
echo "- Confirme se o certificado SSL está funcionando"
echo "- Teste diretamente a API: curl -I https://api.linkcharts.com.br/health"
