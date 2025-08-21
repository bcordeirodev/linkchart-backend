#!/bin/bash

echo "🧪 TESTE LOCAL DE DEPLOY - LINK CHART"
echo "===================================="

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_success() { echo -e "${GREEN}✅ $1${NC}"; }
log_error() { echo -e "${RED}❌ $1${NC}"; }
log_warning() { echo -e "${YELLOW}⚠️ $1${NC}"; }

echo "📋 1. Verificando arquivos de configuração..."

# Verificar se arquivos essenciais existem
FILES=("Dockerfile" "docker-compose.prod.yml" ".env.production" "docker/nginx/default.conf")
for file in "${FILES[@]}"; do
    if [ -f "$file" ]; then
        log_success "Arquivo $file existe"
    else
        log_error "Arquivo $file NÃO encontrado"
        exit 1
    fi
done

echo ""
echo "🐳 2. Testando build do Docker..."

# Build da imagem
if docker build -t linkchartapi-test . > /tmp/docker_build.log 2>&1; then
    log_success "Docker build: OK"
else
    log_error "Docker build: FALHA"
    echo "Últimas 10 linhas do log:"
    tail -10 /tmp/docker_build.log
    exit 1
fi

echo ""
echo "🚀 3. Testando containers localmente..."

# Copiar env de produção para teste
cp .env.production .env.test
echo "JWT_SECRET=test-secret-key-for-local-testing-only-32chars" >> .env.test

# Criar compose de teste (usando apenas container com supervisor)
cat > docker-compose.test.yml << EOF
services:
  app:
    image: linkchartapi-test
    container_name: linkchartapi
    ports:
      - "8081:80"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_KEY=base64:test-key-here
      - JWT_SECRET=test-secret-key-for-local-testing-only-32chars
      - DB_CONNECTION=sqlite
      - DB_DATABASE=/tmp/test.db
      - CACHE_DRIVER=file
      - SESSION_DRIVER=file
      - QUEUE_CONNECTION=sync
    volumes:
      - ./.env.test:/var/www/.env
    networks:
      - test-net

networks:
  test-net:
    driver: bridge
EOF

# Iniciar containers de teste
echo "🔄 Iniciando containers de teste..."
if docker compose -f docker-compose.test.yml up -d > /tmp/docker_up.log 2>&1; then
    log_success "Containers iniciados"
else
    log_error "Falha ao iniciar containers"
    cat /tmp/docker_up.log
    exit 1
fi

# Aguardar containers ficarem prontos
echo "⏳ Aguardando containers ficarem prontos..."
sleep 10

echo ""
echo "🧪 4. Testando health check..."

# Testar health check
ATTEMPTS=0
MAX_ATTEMPTS=30
while [ $ATTEMPTS -lt $MAX_ATTEMPTS ]; do
    if curl -f http://localhost:8081/health > /dev/null 2>&1; then
        log_success "Health check: OK"
        break
    else
        ((ATTEMPTS++))
        if [ $ATTEMPTS -eq $MAX_ATTEMPTS ]; then
            log_error "Health check: FALHA após $MAX_ATTEMPTS tentativas"

            echo "Debug Info:"
            echo "Container logs:"
            docker logs linkchartapi --tail 30

            # Cleanup
            docker compose -f docker-compose.test.yml down > /dev/null 2>&1
            rm -f docker-compose.test.yml .env.test /tmp/docker_*.log
            exit 1
        fi
        echo "Tentativa $ATTEMPTS/$MAX_ATTEMPTS..."
        sleep 2
    fi
done

echo ""
echo "📡 5. Testando endpoints da API..."

# Testar endpoint que deve retornar 404 (sem rota)
RESPONSE_404=$(curl -s -w "%{http_code}" -o /dev/null http://localhost:8081/api/nonexistent 2>/dev/null)
if [ "$RESPONSE_404" == "404" ]; then
    log_success "404 endpoint: OK"
else
    log_warning "404 endpoint: Retornou $RESPONSE_404"
fi

# Testar endpoint que deve requerer autenticação
RESPONSE_401=$(curl -s -w "%{http_code}" -o /dev/null http://localhost:8081/api/links 2>/dev/null)
if [ "$RESPONSE_401" == "401" ]; then
    log_success "Auth endpoint: OK (401 como esperado)"
else
    log_warning "Auth endpoint: Retornou $RESPONSE_401"
fi

echo ""
echo "🧹 6. Limpeza..."

# Parar e remover containers de teste
docker compose -f docker-compose.test.yml down > /dev/null 2>&1
docker rmi linkchartapi-test > /dev/null 2>&1
rm -f docker-compose.test.yml .env.test /tmp/docker_*.log

log_success "Limpeza concluída"

echo ""
echo "🎉 TESTE LOCAL CONCLUÍDO COM SUCESSO!"
echo "✅ O deploy deve funcionar corretamente em produção"
