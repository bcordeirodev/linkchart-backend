#!/bin/bash

echo "🌐 Testando CORS - Backend Laravel"
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

echo ""
echo "🎯 Testando requisição OPTIONS (CORS Preflight)..."
echo "URL: http://localhost:8000/api/link"
echo "Method: OPTIONS"
echo "Origin: http://localhost:3000"
echo ""

# Testar requisição OPTIONS
RESPONSE=$(curl -s -i 'http://localhost:8000/api/link' \
  -X 'OPTIONS' \
  -H 'Accept: */*' \
  -H 'Access-Control-Request-Headers: authorization,content-type' \
  -H 'Access-Control-Request-Method: GET' \
  -H 'Origin: http://localhost:3000' \
  -H 'Sec-Fetch-Mode: cors' \
  -H 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1')

echo "📊 Resposta completa:"
echo "===================="
echo "$RESPONSE"
echo ""

# Extrair headers CORS importantes
echo "🔍 Análise dos Headers CORS:"
echo "============================"

HTTP_STATUS=$(echo "$RESPONSE" | head -1 | cut -d' ' -f2)
echo "HTTP Status: $HTTP_STATUS"

ACCESS_CONTROL_ALLOW_ORIGIN=$(echo "$RESPONSE" | grep -i "access-control-allow-origin" | cut -d: -f2- | tr -d '\r\n' | sed 's/^ *//')
ACCESS_CONTROL_ALLOW_METHODS=$(echo "$RESPONSE" | grep -i "access-control-allow-methods" | cut -d: -f2- | tr -d '\r\n' | sed 's/^ *//')
ACCESS_CONTROL_ALLOW_HEADERS=$(echo "$RESPONSE" | grep -i "access-control-allow-headers" | cut -d: -f2- | tr -d '\r\n' | sed 's/^ *//')
ACCESS_CONTROL_MAX_AGE=$(echo "$RESPONSE" | grep -i "access-control-max-age" | cut -d: -f2- | tr -d '\r\n' | sed 's/^ *//')

echo ""
if [ -n "$ACCESS_CONTROL_ALLOW_ORIGIN" ]; then
    echo "✅ Access-Control-Allow-Origin: $ACCESS_CONTROL_ALLOW_ORIGIN"
else
    echo "❌ Access-Control-Allow-Origin: NÃO ENCONTRADO"
fi

if [ -n "$ACCESS_CONTROL_ALLOW_METHODS" ]; then
    echo "✅ Access-Control-Allow-Methods: $ACCESS_CONTROL_ALLOW_METHODS"
else
    echo "❌ Access-Control-Allow-Methods: NÃO ENCONTRADO"
fi

if [ -n "$ACCESS_CONTROL_ALLOW_HEADERS" ]; then
    echo "✅ Access-Control-Allow-Headers: $ACCESS_CONTROL_ALLOW_HEADERS"
else
    echo "❌ Access-Control-Allow-Headers: NÃO ENCONTRADO"
fi

if [ -n "$ACCESS_CONTROL_MAX_AGE" ]; then
    echo "✅ Access-Control-Max-Age: $ACCESS_CONTROL_MAX_AGE"
else
    echo "⚠️  Access-Control-Max-Age: NÃO ENCONTRADO (opcional)"
fi

# Análise do resultado
echo ""
echo "🎯 Análise do Resultado:"
echo "======================="

case $HTTP_STATUS in
    200|204)
        echo "✅ CORS Preflight bem-sucedido!"
        if [ -n "$ACCESS_CONTROL_ALLOW_ORIGIN" ] && [ -n "$ACCESS_CONTROL_ALLOW_METHODS" ] && [ -n "$ACCESS_CONTROL_ALLOW_HEADERS" ]; then
            echo "✅ Todos os headers CORS necessários estão presentes"
            echo "✅ Frontend pode fazer requisições para este endpoint"
        else
            echo "⚠️  Alguns headers CORS estão faltando"
        fi
        ;;
    404)
        echo "❌ Endpoint não encontrado - Verifique as rotas"
        ;;
    405)
        echo "❌ Método OPTIONS não permitido - CORS pode não estar configurado"
        ;;
    *)
        echo "⚠️  Status HTTP inesperado: $HTTP_STATUS"
        ;;
esac

# Testar também uma requisição GET normal para comparar
echo ""
echo "🔄 Testando requisição GET normal para comparação..."
GET_RESPONSE=$(curl -s -i 'http://localhost:8000/api/link' \
  -H 'Origin: http://localhost:3000' \
  -H 'Accept: application/json')

GET_STATUS=$(echo "$GET_RESPONSE" | head -1 | cut -d' ' -f2)
GET_CORS_ORIGIN=$(echo "$GET_RESPONSE" | grep -i "access-control-allow-origin" | cut -d: -f2- | tr -d '\r\n' | sed 's/^ *//')

echo "GET Status: $GET_STATUS"
if [ -n "$GET_CORS_ORIGIN" ]; then
    echo "✅ GET também retorna CORS headers: $GET_CORS_ORIGIN"
else
    echo "❌ GET não retorna CORS headers"
fi

# Limpar
echo ""
echo "🧹 Finalizando servidor..."
kill $SERVER_PID 2>/dev/null
sleep 2

echo "🎯 Teste CORS concluído!"
