#!/bin/bash

echo "🔍 DIAGNÓSTICO CORS - SERVIDOR DE PRODUÇÃO"
echo "=========================================="

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}1. Verificando localização atual...${NC}"
pwd
echo ""

echo -e "${YELLOW}2. Verificando containers ativos...${NC}"
docker compose -f docker-compose.prod.yml ps
echo ""

echo -e "${YELLOW}3. Verificando configuração nginx no container...${NC}"
docker exec linkchartapi cat /etc/nginx/conf.d/default.conf | head -50
echo ""

echo -e "${YELLOW}4. Testando CORS OPTIONS localmente...${NC}"
curl -I 'http://localhost:8000/api/auth/login' \
  -X OPTIONS \
  -H 'Origin: http://134.209.33.182' \
  -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: Content-Type'
echo ""

echo -e "${YELLOW}5. Testando CORS POST localmente...${NC}"
curl 'http://localhost:8000/api/auth/login' \
  -X POST \
  -H 'Content-Type: application/json' \
  -H 'Origin: http://134.209.33.182' \
  --data-raw '{"email":"test@example.com","password":"password"}' -v
echo ""

echo -e "${YELLOW}6. Verificando logs nginx...${NC}"
docker exec linkchartapi tail -20 /var/log/nginx/error.log
echo ""

echo -e "${YELLOW}7. Verificando se nginx está usando a configuração correta...${NC}"
docker exec linkchartapi nginx -T | grep -A 10 -B 5 "Access-Control"
echo ""

echo -e "${YELLOW}8. Testando health check...${NC}"
curl -v http://localhost:8000/health
echo ""

echo -e "${GREEN}Diagnóstico concluído!${NC}"
echo "Execute este script no servidor de produção para investigar o CORS."
