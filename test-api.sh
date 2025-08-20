#!/bin/bash

echo "🧪 Testando API do Backend Laravel"
echo "=================================="

# Iniciar servidor
echo "🚀 Iniciando servidor..."
php artisan serve --port=8000 &
SERVER_PID=$!

# Aguardar servidor inicializar
echo "⏳ Aguardando servidor inicializar..."
sleep 5

# Testar se servidor está respondendo
echo "🔍 Testando conectividade básica..."
if curl -s http://localhost:8000 > /dev/null; then
    echo "✅ Servidor respondendo!"
else
    echo "❌ Servidor não está respondendo"
    kill $SERVER_PID 2>/dev/null
    exit 1
fi

# Testar a requisição específica
echo ""
echo "🎯 Testando requisição de criação de URL..."
echo "URL: http://localhost:8000/api/gerar-url"
echo ""

RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" 'http://localhost:8000/api/gerar-url' \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0OjgwMDAvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3NTUzNTI4MjYsImV4cCI6MTc1Nzk0NDgyNiwibmJmIjoxNzU1MzUyODI2LCJqdGkiOiI1UmZvcElmSDJ5clFrbXdMIiwic3ViIjoiMiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.mkk-23ZOW0aCP-pi_djvoXNjS4cVY7eFAGoWXMUjyIE' \
  -H 'Referer: http://localhost:3000/' \
  -H 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data-raw '{"original_url":"https://www.google.com","title":"asdasd","slug":"","description":"asdasds","is_active":true,"utm_source":"","utm_medium":"","utm_campaign":"","utm_term":"","utm_content":""}')

# Extrair código HTTP
HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
RESPONSE_BODY=$(echo "$RESPONSE" | sed '/HTTP_CODE:/d')

echo "📊 Resultado:"
echo "HTTP Code: $HTTP_CODE"
echo "Response Body:"
echo "$RESPONSE_BODY" | jq . 2>/dev/null || echo "$RESPONSE_BODY"

# Analisar resultado
case $HTTP_CODE in
    200|201)
        echo ""
        echo "✅ SUCESSO! API funcionando corretamente"
        ;;
    400)
        echo ""
        echo "⚠️  Bad Request - Verifique os dados enviados"
        ;;
    401)
        echo ""
        echo "🔐 Unauthorized - Token JWT pode estar expirado"
        ;;
    422)
        echo ""
        echo "📝 Validation Error - Dados inválidos"
        ;;
    500)
        echo ""
        echo "💥 Internal Server Error - Erro no servidor"
        echo "Verificando logs..."
        tail -5 storage/logs/laravel.log
        ;;
    *)
        echo ""
        echo "❓ Código HTTP inesperado: $HTTP_CODE"
        ;;
esac

# Limpar
echo ""
echo "🧹 Finalizando servidor..."
kill $SERVER_PID 2>/dev/null
sleep 2

echo "🎯 Teste concluído!"
