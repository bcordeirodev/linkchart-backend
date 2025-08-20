#!/bin/bash

# ==========================================
# CONFIGURAÇÃO DE AMBIENTE PERSONALIZADA
# Servidor: 138.197.121.81
# ==========================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}⚙️  Configurando variáveis de ambiente...${NC}"

# ==========================================
# GERAR CHAVES E SECRETS
# ==========================================

echo -e "${BLUE}🔐 Gerando chaves de segurança...${NC}"

# Gerar APP_KEY
APP_KEY=$(openssl rand -base64 32)
echo -e "${GREEN}✅ APP_KEY gerada${NC}"

# Gerar JWT_SECRET
JWT_SECRET=$(openssl rand -base64 64)
echo -e "${GREEN}✅ JWT_SECRET gerada${NC}"

# Gerar senhas seguras
DB_PASSWORD="oBruno!oo1o_db_$(openssl rand -hex 8)"
REDIS_PASSWORD="oBruno!oo1o_redis_$(openssl rand -hex 8)"

echo -e "${GREEN}✅ Senhas geradas${NC}"

# ==========================================
# CRIAR ARQUIVO .env PERSONALIZADO
# ==========================================

echo -e "${BLUE}📝 Criando arquivo .env personalizado...${NC}"

cat > .env << EOF
# ===========================================
# CONFIGURAÇÃO DE PRODUÇÃO - DIGITALOCEAN
# Servidor: 138.197.121.81
# ===========================================

# Application
APP_NAME="Link Chart"
APP_ENV=production
APP_KEY=base64:${APP_KEY}
APP_DEBUG=false
APP_URL=http://138.197.121.81
FRONTEND_URL=http://138.197.121.81:3000

APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=pt_BR

# Performance
APP_MAINTENANCE_DRIVER=file
PHP_CLI_SERVER_WORKERS=8
BCRYPT_ROUNDS=12

# Logging - Otimizado para produção
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

# Database - PostgreSQL otimizado para produção
DB_CONNECTION=pgsql
DB_HOST=database
DB_PORT=5432
DB_DATABASE=linkchartprod
DB_USERNAME=linkchartuser
DB_PASSWORD=${DB_PASSWORD}

# Session - Otimizada para produção
SESSION_DRIVER=redis
SESSION_LIFETIME=1440
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=138.197.121.81
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

# Broadcasting
BROADCAST_CONNECTION=redis

# Filesystem - Local para início
FILESYSTEM_DISK=local

# Queue - Redis para melhor performance
QUEUE_CONNECTION=redis

# Cache - Redis para alta performance
CACHE_STORE=redis
CACHE_PREFIX=linkchartcache

# Redis - Configuração otimizada
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2

# Mail - Configuração básica (ajustar depois)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@linkchartapp.com"
MAIL_FROM_NAME="\${APP_NAME}"

# JWT - Configurações de segurança
JWT_SECRET=${JWT_SECRET}
JWT_TTL=1440
JWT_REFRESH_TTL=20160
JWT_ALGO=HS256

# Rate Limiting - Configurações otimizadas para produção
RATE_LIMIT_ENABLED=true
RATE_LIMIT_MAX_ATTEMPTS=100
RATE_LIMIT_DECAY_MINUTES=1

# CORS - Configuração para desenvolvimento inicial
SANCTUM_STATEFUL_DOMAINS=138.197.121.81:3000,localhost:3000
EOF

echo -e "${GREEN}✅ Arquivo .env criado!${NC}"

# ==========================================
# ATUALIZAR DOCKER COMPOSE COM SENHAS
# ==========================================

echo -e "${BLUE}🐳 Atualizando Docker Compose com senhas...${NC}"

# Criar docker-compose personalizado
cat > docker-compose.prod.yml << EOF
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: linkchartapi
    restart: unless-stopped
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
    ports:
      - "80:80"
    volumes:
      - ./storage/logs:/var/www/storage/logs
      - ./storage/app:/var/www/storage/app
    depends_on:
      - database
      - redis
    networks:
      - linkchartnet

  database:
    image: postgres:15-alpine
    container_name: linkchartdb
    restart: unless-stopped
    environment:
      POSTGRES_DB: linkchartprod
      POSTGRES_USER: linkchartuser
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    networks:
      - linkchartnet

  redis:
    image: redis:7-alpine
    container_name: linkchartredis
    restart: unless-stopped
    command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD}
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    networks:
      - linkchartnet

volumes:
  postgres_data:
  redis_data:

networks:
  linkchartnet:
    driver: bridge
EOF

echo -e "${GREEN}✅ Docker Compose atualizado!${NC}"

# ==========================================
# MOSTRAR INFORMAÇÕES IMPORTANTES
# ==========================================

echo ""
echo -e "${GREEN}🎉 CONFIGURAÇÃO CONCLUÍDA!${NC}"
echo ""
echo -e "${YELLOW}📋 CREDENCIAIS GERADAS:${NC}"
echo -e "${BLUE}Database Password:${NC} ${DB_PASSWORD}"
echo -e "${BLUE}Redis Password:${NC} ${REDIS_PASSWORD}"
echo ""
echo -e "${YELLOW}🔗 URLs de Acesso:${NC}"
echo -e "${BLUE}API Backend:${NC} http://138.197.121.81"
echo -e "${BLUE}Database:${NC} 138.197.121.81:5432"
echo -e "${BLUE}Redis:${NC} 138.197.121.81:6379"
echo ""
echo -e "${YELLOW}📝 Próximo passo:${NC}"
echo "Executar: ./deploy.sh"
echo ""

# Salvar credenciais em arquivo seguro
cat > credentials.txt << EOF
=== CREDENCIAIS DO LINK CHART ===
Servidor: 138.197.121.81
Data: $(date)

Database Password: ${DB_PASSWORD}
Redis Password: ${REDIS_PASSWORD}
APP_KEY: base64:${APP_KEY}
JWT_SECRET: ${JWT_SECRET}

URLs:
- API: http://138.197.121.81
- Database: 138.197.121.81:5432
- Redis: 138.197.121.81:6379
EOF

chmod 600 credentials.txt
echo -e "${GREEN}✅ Credenciais salvas em credentials.txt${NC}"
