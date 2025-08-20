#!/bin/bash

# ==========================================
# SETUP PERSONALIZADO DIGITALOCEAN
# Servidor: 138.197.121.81
# ==========================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}🚀 Configurando servidor DigitalOcean para Link Chart${NC}"
echo -e "${YELLOW}IP: 138.197.121.81${NC}"
echo ""

# ==========================================
# PASSO 1: CONECTAR E ATUALIZAR SISTEMA
# ==========================================

echo -e "${BLUE}📦 Atualizando sistema...${NC}"

# Atualizar repositórios e sistema
apt update && apt upgrade -y

# Instalar dependências essenciais
apt install -y \
    curl \
    wget \
    git \
    unzip \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    gnupg \
    lsb-release \
    ufw \
    htop \
    nano \
    vim

echo -e "${GREEN}✅ Sistema atualizado!${NC}"

# ==========================================
# PASSO 2: INSTALAR DOCKER
# ==========================================

echo -e "${BLUE}🐳 Instalando Docker...${NC}"

# Remover versões antigas do Docker
apt remove -y docker docker-engine docker.io containerd runc || true

# Adicionar repositório oficial do Docker
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

# Instalar Docker
apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Iniciar e habilitar Docker
systemctl start docker
systemctl enable docker

# Testar instalação
docker --version
docker compose version

echo -e "${GREEN}✅ Docker instalado!${NC}"

# ==========================================
# PASSO 3: CONFIGURAR USUÁRIO E PERMISSÕES
# ==========================================

echo -e "${BLUE}👤 Configurando usuário da aplicação...${NC}"

# Criar usuário para aplicação se não existir
if ! id "linkchartapp" &>/dev/null; then
    useradd -m -s /bin/bash linkchartapp
    usermod -aG docker linkchartapp
    echo -e "${GREEN}✅ Usuário linkchartapp criado!${NC}"
else
    echo -e "${YELLOW}ℹ️  Usuário linkchartapp já existe${NC}"
fi

# Criar diretórios necessários
mkdir -p /var/www
chown -R linkchartapp:linkchartapp /var/www

echo -e "${GREEN}✅ Permissões configuradas!${NC}"

# ==========================================
# PASSO 4: CONFIGURAR FIREWALL
# ==========================================

echo -e "${BLUE}🔥 Configurando firewall...${NC}"

# Resetar UFW
ufw --force reset

# Configurar regras básicas
ufw default deny incoming
ufw default allow outgoing

# Permitir SSH, HTTP e HTTPS
ufw allow ssh
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 5432/tcp  # PostgreSQL para desenvolvimento
ufw allow 6379/tcp  # Redis para desenvolvimento

# Ativar firewall
ufw --force enable

# Mostrar status
ufw status

echo -e "${GREEN}✅ Firewall configurado!${NC}"

# ==========================================
# PASSO 5: INSTALAR CERTBOT PARA SSL
# ==========================================

echo -e "${BLUE}🔒 Instalando Certbot para SSL...${NC}"

# Instalar Certbot
apt install -y certbot python3-certbot-nginx

echo -e "${GREEN}✅ Certbot instalado!${NC}"

# ==========================================
# PASSO 6: CLONAR REPOSITÓRIO
# ==========================================

echo -e "${BLUE}📥 Clonando repositório...${NC}"

# Mudar para usuário da aplicação
su - linkchartapp << 'EOF'
cd /var/www

# Remover diretório se existir
rm -rf linkchartapi

# Clonar repositório
git clone https://github.com/bcordeirodev/linkchart-backend.git linkchartapi
cd linkchartapi

# Verificar se clonou corretamente
ls -la

echo "✅ Repositório clonado em /var/www/linkchartapi"
EOF

echo -e "${GREEN}✅ Repositório clonado!${NC}"

# ==========================================
# INFORMAÇÕES FINAIS
# ==========================================

echo ""
echo -e "${GREEN}🎉 CONFIGURAÇÃO INICIAL CONCLUÍDA!${NC}"
echo ""
echo -e "${YELLOW}📋 PRÓXIMOS PASSOS:${NC}"
echo "1. Configurar arquivo .env"
echo "2. Executar deploy da aplicação"
echo "3. Configurar SSL/domínio"
echo ""
echo -e "${BLUE}📁 Estrutura criada:${NC}"
echo "- Usuário: linkchartapp"
echo "- Projeto: /var/www/linkchartapi"
echo "- Docker: Instalado e funcionando"
echo "- Firewall: Configurado"
echo ""
echo -e "${YELLOW}🔑 Para continuar:${NC}"
echo "su - linkchartapp"
echo "cd /var/www/linkchartapi"
echo ""
