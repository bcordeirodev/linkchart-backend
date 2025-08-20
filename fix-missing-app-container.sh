#!/bin/bash

# ==========================================
# CORRIGIR CONTAINER DA APLICAÇÃO AUSENTE
# Link Chart API - Solução Específica
# ==========================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}🔧 Corrigindo container da aplicação ausente...${NC}"
echo ""

# Verificar se estamos no diretório correto
if [[ ! -f "docker-compose.prod.yml" ]]; then
    echo -e "${RED}❌ docker-compose.prod.yml não encontrado!${NC}"
    echo -e "${YELLOW}💡 Execute este script no diretório: /var/www/linkchartapi${NC}"
    exit 1
fi

echo -e "${BLUE}📋 Status atual dos containers:${NC}"
docker compose -f docker-compose.prod.yml ps

echo ""
echo -e "${BLUE}🛑 Parando todos os containers...${NC}"
docker compose -f docker-compose.prod.yml down

echo ""
echo -e "${BLUE}🧹 Limpando recursos órfãos...${NC}"
docker system prune -f

echo ""
echo -e "${BLUE}🏗️  Verificando Dockerfile...${NC}"
if [[ ! -f "Dockerfile" ]]; then
    echo -e "${RED}❌ Dockerfile não encontrado!${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Dockerfile encontrado${NC}"

echo ""
echo -e "${BLUE}🔍 Verificando configuração do docker-compose...${NC}"
if ! docker compose -f docker-compose.prod.yml config > /dev/null 2>&1; then
    echo -e "${RED}❌ Erro na configuração do docker-compose${NC}"
    docker compose -f docker-compose.prod.yml config
    exit 1
fi

echo -e "${GREEN}✅ Configuração do docker-compose OK${NC}"

echo ""
echo -e "${BLUE}🏗️  Construindo container da aplicação (sem cache)...${NC}"
docker compose -f docker-compose.prod.yml build --no-cache app

echo ""
echo -e "${BLUE}🚀 Iniciando todos os containers...${NC}"
docker compose -f docker-compose.prod.yml up -d

echo ""
echo -e "${BLUE}⏳ Aguardando containers ficarem prontos...${NC}"
sleep 30

echo ""
echo -e "${BLUE}📊 Verificando status dos containers...${NC}"
docker compose -f docker-compose.prod.yml ps

echo ""
echo -e "${BLUE}🔍 Verificando se o container da aplicação está rodando...${NC}"
if docker ps --format "table {{.Names}}" | grep -q "^linkchartapi$"; then
    echo -e "${GREEN}✅ Container linkchartapi está rodando!${NC}"

    echo ""
    echo -e "${BLUE}⚙️  Configurando Laravel...${NC}"

    # Aguardar um pouco mais para o container ficar totalmente pronto
    sleep 10

    echo -e "${YELLOW}📊 Executando migrações...${NC}"
    docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force || {
        echo -e "${YELLOW}⚠️  Primeira tentativa falhou, tentando novamente...${NC}"
        sleep 10
        docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
    }

    echo -e "${YELLOW}🚀 Aplicando otimizações...${NC}"
    docker compose -f docker-compose.prod.yml exec -T app php artisan optimize || true
    docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache || true
    docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache || true

    echo -e "${YELLOW}🔗 Criando storage link...${NC}"
    docker compose -f docker-compose.prod.yml exec -T app php artisan storage:link || true

else
    echo -e "${RED}❌ Container linkchartapi ainda não está rodando${NC}"
    echo ""
    echo -e "${YELLOW}🔍 Logs do container da aplicação:${NC}"
    docker compose -f docker-compose.prod.yml logs app || echo "Não foi possível obter logs"
    exit 1
fi

echo ""
echo -e "${BLUE}🔌 Verificando portas...${NC}"
netstat -tlnp | grep -E ":(80|443|5432|6379)" || echo "Nenhuma porta encontrada"

echo ""
echo -e "${BLUE}🏥 Testando API...${NC}"
sleep 5

# Testar diferentes URLs
urls=("http://localhost/api/health" "http://127.0.0.1/api/health" "http://localhost:80/api/health")

for url in "${urls[@]}"; do
    echo -n "Testando $url: "
    if curl -f -s --connect-timeout 10 "$url" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ OK${NC}"
        api_working=true
        break
    else
        echo -e "${RED}❌ FALHOU${NC}"
    fi
done

if [[ "$api_working" != "true" ]]; then
    echo ""
    echo -e "${YELLOW}⚠️  API ainda não está respondendo${NC}"
    echo -e "${YELLOW}🔍 Verificando logs do Nginx...${NC}"
    docker logs linkchartnginx --tail=10
    echo ""
    echo -e "${YELLOW}🔍 Verificando logs da aplicação...${NC}"
    docker logs linkchartapi --tail=10 || echo "Container da aplicação não encontrado"
fi

echo ""
echo -e "${GREEN}🎉 Correção concluída!${NC}"

echo ""
echo -e "${YELLOW}📊 Status final dos containers:${NC}"
docker compose -f docker-compose.prod.yml ps

echo ""
echo -e "${YELLOW}🔗 URLs para testar:${NC}"
echo "http://localhost/api/health"
echo "http://138.197.121.81/api/health"
echo "http://138.197.121.81:8080/api/health"

echo ""
echo -e "${YELLOW}📋 Se ainda houver problemas:${NC}"
echo -e "${BLUE}Ver logs:${NC} docker compose -f docker-compose.prod.yml logs -f"
echo -e "${BLUE}Reiniciar:${NC} docker compose -f docker-compose.prod.yml restart"
echo -e "${BLUE}Entrar no container:${NC} docker compose -f docker-compose.prod.yml exec app bash"
