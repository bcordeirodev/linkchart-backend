#!/bin/bash

# ==========================================
# SCRIPT DE DEPLOY AUTOMÁTICO - DIGITALOCEAN
# ==========================================

set -e

echo "🚀 Iniciando deploy do Link Chart Backend..."

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configurações
PROJECT_DIR="/var/www/linkchartapi"
BRANCH="main"
PHP_VERSION="8.2"

echo -e "${BLUE}📁 Navegando para diretório do projeto...${NC}"
cd $PROJECT_DIR

echo -e "${BLUE}🔄 Fazendo backup da aplicação atual...${NC}"
sudo cp -R $PROJECT_DIR $PROJECT_DIR-backup-$(date +%Y%m%d_%H%M%S)

echo -e "${BLUE}📥 Fazendo pull das últimas alterações...${NC}"
git fetch origin
git reset --hard origin/$BRANCH

echo -e "${BLUE}📦 Instalando dependências do Composer...${NC}"
composer install --optimize-autoloader --no-dev --no-interaction

echo -e "${BLUE}🔧 Configurando permissões...${NC}"
sudo chown -R www-data:www-data $PROJECT_DIR
sudo chmod -R 755 $PROJECT_DIR
sudo chmod -R 775 $PROJECT_DIR/storage
sudo chmod -R 775 $PROJECT_DIR/bootstrap/cache

echo -e "${BLUE}🗃️ Executando migrações do banco...${NC}"
php artisan migrate --force

echo -e "${BLUE}🧹 Limpando caches...${NC}"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

echo -e "${BLUE}⚡ Otimizando para produção...${NC}"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo -e "${BLUE}🔄 Reiniciando serviços...${NC}"
sudo supervisorctl restart linkchartapi-worker:*
sudo systemctl reload nginx
sudo systemctl reload php${PHP_VERSION}-fpm

echo -e "${BLUE}🧪 Verificando saúde da aplicação...${NC}"
if curl -f -s http://localhost/api/health > /dev/null; then
    echo -e "${GREEN}✅ Deploy concluído com sucesso!${NC}"
    echo -e "${GREEN}🌐 API disponível em: $(curl -s http://localhost/api/health | jq -r '.app_url')${NC}"
else
    echo -e "${RED}❌ Erro no deploy! Verificando logs...${NC}"
    tail -n 50 storage/logs/laravel.log
    exit 1
fi

echo -e "${YELLOW}📊 Estatísticas do deploy:${NC}"
echo -e "- Versão PHP: $(php -v | head -n1)"
echo -e "- Versão Laravel: $(php artisan --version)"
echo -e "- Espaço em disco: $(df -h / | tail -1 | awk '{print $4}') disponível"
echo -e "- Memória: $(free -h | grep '^Mem:' | awk '{print $7}') disponível"

echo -e "${GREEN}🎉 Deploy finalizado!${NC}"
