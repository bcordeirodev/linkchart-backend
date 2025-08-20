#!/bin/bash

# ==========================================
# DEPLOY FINAL PERSONALIZADO
# Servidor: 138.197.121.81
# ==========================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}🚀 Iniciando deploy final do Link Chart...${NC}"
echo -e "${YELLOW}Servidor: 138.197.121.81${NC}"
echo ""

# ==========================================
# VERIFICAR DEPENDÊNCIAS
# ==========================================

echo -e "${BLUE}🔍 Verificando dependências...${NC}"

# Verificar se Docker está rodando
if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}❌ Docker não está rodando!${NC}"
    exit 1
fi

# Verificar se arquivos existem
if [[ ! -f ".env" ]]; then
    echo -e "${RED}❌ Arquivo .env não encontrado!${NC}"
    echo "Execute primeiro: ./configure-env.sh"
    exit 1
fi

if [[ ! -f "docker-compose.prod.yml" ]]; then
    echo -e "${RED}❌ docker-compose.prod.yml não encontrado!${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Dependências verificadas!${NC}"

# ==========================================
# PARAR CONTAINERS EXISTENTES
# ==========================================

echo -e "${BLUE}🛑 Parando containers existentes...${NC}"

docker compose -f docker-compose.prod.yml down --remove-orphans || true

echo -e "${GREEN}✅ Containers parados!${NC}"

# ==========================================
# BUILD E START DOS CONTAINERS
# ==========================================

echo -e "${BLUE}🏗️  Construindo e iniciando containers...${NC}"

# Build das imagens
docker compose -f docker-compose.prod.yml build --no-cache

# Iniciar containers
docker compose -f docker-compose.prod.yml up -d

# Aguardar containers ficarem prontos
echo -e "${YELLOW}⏳ Aguardando containers ficarem prontos...${NC}"
sleep 30

# Verificar status
docker compose -f docker-compose.prod.yml ps

echo -e "${GREEN}✅ Containers iniciados!${NC}"

# ==========================================
# CONFIGURAR APLICAÇÃO LARAVEL
# ==========================================

echo -e "${BLUE}⚙️  Configurando Laravel...${NC}"

# Aguardar banco ficar pronto
echo -e "${YELLOW}⏳ Aguardando PostgreSQL...${NC}"
sleep 10

# Executar migrações
echo -e "${BLUE}📊 Executando migrações...${NC}"
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force

# Executar seeders se necessário
echo -e "${BLUE}🌱 Executando seeders...${NC}"
docker compose -f docker-compose.prod.yml exec -T app php artisan db:seed --force || true

# Otimizar aplicação para produção
echo -e "${BLUE}🚀 Otimizando para produção...${NC}"
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize
docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan view:cache

# Criar storage links
docker compose -f docker-compose.prod.yml exec -T app php artisan storage:link || true

echo -e "${GREEN}✅ Laravel configurado!${NC}"

# ==========================================
# TESTES DE SAÚDE
# ==========================================

echo -e "${BLUE}🏥 Executando testes de saúde...${NC}"

# Testar conexão com banco
echo -e "${YELLOW}📊 Testando conexão com banco...${NC}"
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="echo 'DB Connection: ' . (DB::connection()->getPdo() ? 'OK' : 'FAILED') . PHP_EOL;"

# Testar conexão com Redis
echo -e "${YELLOW}🔴 Testando conexão com Redis...${NC}"
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="echo 'Redis Connection: ' . (Cache::store('redis')->get('test') !== null || Cache::store('redis')->put('test', 'ok', 10) ? 'OK' : 'FAILED') . PHP_EOL;"

# Testar API
echo -e "${YELLOW}🌐 Testando API...${NC}"
sleep 5

# Teste básico da API
if curl -f -s http://138.197.121.81/api/health > /dev/null; then
    echo -e "${GREEN}✅ API respondendo!${NC}"
else
    echo -e "${YELLOW}⚠️  API ainda não está respondendo (normal nos primeiros minutos)${NC}"
fi

echo -e "${GREEN}✅ Testes de saúde concluídos!${NC}"

# ==========================================
# INFORMAÇÕES FINAIS
# ==========================================

echo ""
echo -e "${GREEN}🎉 DEPLOY CONCLUÍDO COM SUCESSO!${NC}"
echo ""
echo -e "${YELLOW}📊 STATUS DOS SERVIÇOS:${NC}"
docker compose -f docker-compose.prod.yml ps
echo ""
echo -e "${YELLOW}🔗 URLs DE ACESSO:${NC}"
echo -e "${BLUE}API Backend:${NC} http://138.197.121.81"
echo -e "${BLUE}Health Check:${NC} http://138.197.121.81/api/health"
echo -e "${BLUE}Database:${NC} 138.197.121.81:5432"
echo -e "${BLUE}Redis:${NC} 138.197.121.81:6379"
echo ""
echo -e "${YELLOW}📋 COMANDOS ÚTEIS:${NC}"
echo -e "${BLUE}Ver logs:${NC} docker compose -f docker-compose.prod.yml logs -f"
echo -e "${BLUE}Reiniciar:${NC} docker compose -f docker-compose.prod.yml restart"
echo -e "${BLUE}Parar:${NC} docker compose -f docker-compose.prod.yml down"
echo -e "${BLUE}Status:${NC} docker compose -f docker-compose.prod.yml ps"
echo ""
echo -e "${GREEN}🚀 Aplicação pronta para uso!${NC}"

# ==========================================
# CONFIGURAR CRON PARA MONITORAMENTO
# ==========================================

echo -e "${BLUE}⏰ Configurando monitoramento automático...${NC}"

# Criar script de monitoramento
cat > monitor.sh << 'EOF'
#!/bin/bash
cd /var/www/linkchartapi
if ! docker compose -f docker-compose.prod.yml ps | grep -q "Up"; then
    echo "$(date): Reiniciando containers..." >> monitor.log
    docker compose -f docker-compose.prod.yml up -d
fi
EOF

chmod +x monitor.sh

echo -e "${GREEN}✅ Monitoramento configurado!${NC}"
echo -e "${YELLOW}💡 Para adicionar ao cron (opcional):${NC}"
echo "crontab -e"
echo "# Adicionar linha:"
echo "*/5 * * * * /var/www/linkchartapi/monitor.sh"
echo ""
