#!/bin/bash

# ========================================
# 🧪 TESTE DE CORS - API LinkCharts
# ========================================

echo "🔍 Testando CORS da API LinkCharts..."
echo "======================================"

API_URL="https://api.linkcharts.com.br"
FRONTEND_URL="https://linkcharts.com.br"

echo ""
echo "📡 1. Testando preflight request (OPTIONS)..."
echo "----------------------------------------------"

# Teste de preflight request
curl -v \
  -X OPTIONS \
  -H "Origin: $FRONTEND_URL" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization" \
  "$API_URL/api/auth/login" \
  2>&1 | grep -E "(Access-Control|HTTP/)"

echo ""
echo "📡 2. Testando request real (GET)..."
echo "------------------------------------"

# Teste de request real
curl -v \
  -X GET \
  -H "Origin: $FRONTEND_URL" \
  -H "Content-Type: application/json" \
  "$API_URL/api/links" \
  2>&1 | grep -E "(Access-Control|HTTP/)"

echo ""
echo "📡 3. Testando health check..."
echo "------------------------------"

# Teste de health check
curl -v \
  -X GET \
  -H "Origin: $FRONTEND_URL" \
  "$API_URL/health" \
  2>&1 | grep -E "(Access-Control|HTTP/)"

echo ""
echo "✅ Teste de CORS concluído!"
echo ""
echo "🔍 Verificações importantes:"
echo "- Access-Control-Allow-Origin deve conter: $FRONTEND_URL"
echo "- Access-Control-Allow-Methods deve conter: GET, POST, PUT, DELETE, OPTIONS"
echo "- Access-Control-Allow-Headers deve conter: Authorization, Content-Type"
echo "- Access-Control-Allow-Credentials deve ser: true"
