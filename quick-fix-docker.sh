#!/bin/bash

# ==========================================
# CORREÇÕES RÁPIDAS - DOCKER DIGITALOCEAN
# Link Chart API - Soluções Automáticas
# ==========================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}🔧 Iniciando correções automáticas do Docker...${NC}"
echo ""

# Verificar se estamos no diretório correto
if [[ ! -f "docker-compose.prod.yml" ]]; then
    echo -e "${RED}❌ docker-compose.prod.yml não encontrado!${NC}"
    echo -e "${YELLOW}💡 Execute este script no diretório da aplicação${NC}"
    exit 1
fi

# ==========================================
# FUNÇÃO: VERIFICAR DOCKER
# ==========================================
check_docker() {
    echo -e "${BLUE}🐳 Verificando Docker...${NC}"

    if ! command -v docker &> /dev/null; then
        echo -e "${RED}❌ Docker não está instalado${NC}"
        return 1
    fi

    if ! docker info &> /dev/null; then
        echo -e "${YELLOW}⚠️  Docker não está rodando, tentando iniciar...${NC}"
        sudo systemctl start docker || service docker start
        sleep 5

        if ! docker info &> /dev/null; then
            echo -e "${RED}❌ Não foi possível iniciar o Docker${NC}"
            return 1
        fi
    fi

    echo -e "${GREEN}✅ Docker está funcionando${NC}"
    return 0
}

# ==========================================
# FUNÇÃO: LIMPAR RECURSOS ÓRFÃOS
# ==========================================
cleanup_docker() {
    echo -e "${BLUE}🧹 Limpando recursos órfãos...${NC}"

    # Parar containers órfãos
    docker compose -f docker-compose.prod.yml down --remove-orphans || true

    # Limpar recursos não utilizados
    docker system prune -f || true
    docker volume prune -f || true
    docker network prune -f || true

    # Remover containers parados
    docker container prune -f || true

    echo -e "${GREEN}✅ Limpeza concluída${NC}"
}

# ==========================================
# FUNÇÃO: VERIFICAR PORTAS
# ==========================================
check_ports() {
    echo -e "${BLUE}🔌 Verificando portas...${NC}"

    ports=(80 443 5432 6379)
    conflicts=false

    for port in "${ports[@]}"; do
        if netstat -tlnp 2>/dev/null | grep -q ":$port " && ! docker ps --format "table {{.Names}}\t{{.Ports}}" | grep -q ":$port->"; then
            echo -e "${RED}⚠️  Porta $port está sendo usada por outro processo:${NC}"
            netstat -tlnp 2>/dev/null | grep ":$port "
            conflicts=true
        fi
    done

    if [[ "$conflicts" == "true" ]]; then
        echo -e "${YELLOW}💡 Considere parar os processos conflitantes ou alterar as portas${NC}"
        return 1
    fi

    echo -e "${GREEN}✅ Portas estão livres${NC}"
    return 0
}

# ==========================================
# FUNÇÃO: VERIFICAR VARIÁVEIS DE AMBIENTE
# ==========================================
check_env() {
    echo -e "${BLUE}🔐 Verificando variáveis de ambiente...${NC}"

    if [[ ! -f ".env" ]]; then
        echo -e "${RED}❌ Arquivo .env não encontrado${NC}"

        if [[ -f ".env.production" ]]; then
            echo -e "${YELLOW}💡 Copiando .env.production para .env${NC}"
            cp .env.production .env
        else
            echo -e "${RED}❌ Nenhum arquivo de ambiente encontrado${NC}"
            return 1
        fi
    fi

    # Verificar variáveis essenciais
    required_vars=("DB_PASSWORD" "REDIS_PASSWORD" "APP_KEY")
    missing_vars=()

    for var in "${required_vars[@]}"; do
        if ! grep -q "^$var=" .env; then
            missing_vars+=("$var")
        fi
    done

    if [[ ${#missing_vars[@]} -gt 0 ]]; then
        echo -e "${RED}❌ Variáveis faltando no .env:${NC}"
        printf '%s\n' "${missing_vars[@]}"

        # Gerar valores faltantes
        echo -e "${YELLOW}💡 Gerando valores faltantes...${NC}"
        for var in "${missing_vars[@]}"; do
            case $var in
                "DB_PASSWORD")
                    echo "DB_PASSWORD=$(openssl rand -base64 32)" >> .env
                    ;;
                "REDIS_PASSWORD")
                    echo "REDIS_PASSWORD=$(openssl rand -base64 32)" >> .env
                    ;;
                "APP_KEY")
                    echo "APP_KEY=base64:$(openssl rand -base64 32)" >> .env
                    ;;
            esac
        done
    fi

    echo -e "${GREEN}✅ Variáveis de ambiente OK${NC}"
    return 0
}

# ==========================================
# FUNÇÃO: CORRIGIR PERMISSÕES
# ==========================================
fix_permissions() {
    echo -e "${BLUE}🔒 Corrigindo permissões...${NC}"

    # Criar diretórios se não existirem
    mkdir -p storage/logs storage/app storage/framework/cache storage/framework/sessions storage/framework/views
    mkdir -p bootstrap/cache

    # Corrigir permissões
    chmod -R 775 storage bootstrap/cache

    # Se estivermos rodando como root, ajustar propriedade
    if [[ $EUID -eq 0 ]]; then
        chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || \
        chown -R 1000:1000 storage bootstrap/cache 2>/dev/null || true
    fi

    echo -e "${GREEN}✅ Permissões corrigidas${NC}"
}

# ==========================================
# FUNÇÃO: REBUILD CONTAINERS
# ==========================================
rebuild_containers() {
    echo -e "${BLUE}🏗️  Reconstruindo containers...${NC}"

    # Parar tudo
    docker compose -f docker-compose.prod.yml down --volumes --remove-orphans || true

    # Build limpo
    docker compose -f docker-compose.prod.yml build --no-cache --pull

    # Iniciar
    docker compose -f docker-compose.prod.yml up -d

    echo -e "${GREEN}✅ Containers reconstruídos${NC}"
}

# ==========================================
# FUNÇÃO: AGUARDAR CONTAINERS
# ==========================================
wait_for_containers() {
    echo -e "${BLUE}⏳ Aguardando containers ficarem prontos...${NC}"

    max_attempts=30
    attempt=0

    while [[ $attempt -lt $max_attempts ]]; do
        if docker compose -f docker-compose.prod.yml ps | grep -q "Up"; then
            echo -e "${GREEN}✅ Containers estão rodando${NC}"
            return 0
        fi

        echo -n "."
        sleep 2
        ((attempt++))
    done

    echo ""
    echo -e "${RED}❌ Timeout aguardando containers${NC}"
    return 1
}

# ==========================================
# FUNÇÃO: CONFIGURAR LARAVEL
# ==========================================
setup_laravel() {
    echo -e "${BLUE}⚙️  Configurando Laravel...${NC}"

    # Aguardar banco ficar pronto
    echo -e "${YELLOW}⏳ Aguardando PostgreSQL...${NC}"
    sleep 15

    # Executar migrações
    echo -e "${BLUE}📊 Executando migrações...${NC}"
    docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force || {
        echo -e "${YELLOW}⚠️  Primeira tentativa de migração falhou, tentando novamente...${NC}"
        sleep 10
        docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
    }

    # Otimizações
    echo -e "${BLUE}🚀 Aplicando otimizações...${NC}"
    docker compose -f docker-compose.prod.yml exec -T app php artisan optimize || true
    docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache || true
    docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache || true
    docker compose -f docker-compose.prod.yml exec -T app php artisan view:cache || true

    # Storage link
    docker compose -f docker-compose.prod.yml exec -T app php artisan storage:link || true

    echo -e "${GREEN}✅ Laravel configurado${NC}"
}

# ==========================================
# FUNÇÃO: TESTAR APLICAÇÃO
# ==========================================
test_application() {
    echo -e "${BLUE}🧪 Testando aplicação...${NC}"

    # Aguardar um pouco
    sleep 10

    # Testar health check
    urls=("http://localhost/api/health" "http://127.0.0.1/api/health")

    for url in "${urls[@]}"; do
        echo -n "Testando $url: "
        if curl -f -s --connect-timeout 10 "$url" > /dev/null 2>&1; then
            echo -e "${GREEN}✅ OK${NC}"
            return 0
        else
            echo -e "${RED}❌ FALHOU${NC}"
        fi
    done

    echo -e "${YELLOW}⚠️  API não está respondendo ainda${NC}"
    return 1
}

# ==========================================
# EXECUÇÃO PRINCIPAL
# ==========================================

echo -e "${CYAN}=====================================${NC}"
echo -e "${CYAN}INICIANDO CORREÇÕES AUTOMÁTICAS${NC}"
echo -e "${CYAN}=====================================${NC}"

# 1. Verificar Docker
if ! check_docker; then
    echo -e "${RED}❌ Falha na verificação do Docker${NC}"
    exit 1
fi

# 2. Verificar variáveis de ambiente
if ! check_env; then
    echo -e "${RED}❌ Falha na verificação das variáveis${NC}"
    exit 1
fi

# 3. Corrigir permissões
fix_permissions

# 4. Verificar portas (não crítico)
check_ports || echo -e "${YELLOW}⚠️  Conflitos de porta detectados${NC}"

# 5. Limpar recursos
cleanup_docker

# 6. Reconstruir containers
if ! rebuild_containers; then
    echo -e "${RED}❌ Falha na reconstrução dos containers${NC}"
    exit 1
fi

# 7. Aguardar containers
if ! wait_for_containers; then
    echo -e "${RED}❌ Containers não ficaram prontos${NC}"
    echo -e "${YELLOW}💡 Verificando logs...${NC}"
    docker compose -f docker-compose.prod.yml logs --tail=20
    exit 1
fi

# 8. Configurar Laravel
if ! setup_laravel; then
    echo -e "${RED}❌ Falha na configuração do Laravel${NC}"
    echo -e "${YELLOW}💡 Verificando logs da aplicação...${NC}"
    docker compose -f docker-compose.prod.yml logs app --tail=20
    exit 1
fi

# 9. Testar aplicação
if ! test_application; then
    echo -e "${YELLOW}⚠️  Aplicação ainda não está respondendo${NC}"
    echo -e "${YELLOW}💡 Isso pode ser normal nos primeiros minutos${NC}"
fi

# ==========================================
# RESUMO FINAL
# ==========================================

echo ""
echo -e "${CYAN}=====================================${NC}"
echo -e "${CYAN}CORREÇÕES CONCLUÍDAS${NC}"
echo -e "${CYAN}=====================================${NC}"

echo ""
echo -e "${GREEN}🎉 Processo de correção concluído!${NC}"

echo ""
echo -e "${YELLOW}📊 Status atual dos containers:${NC}"
docker compose -f docker-compose.prod.yml ps

echo ""
echo -e "${YELLOW}🔗 URLs para testar:${NC}"
echo "http://localhost/api/health"
echo "http://138.197.121.81/api/health"

echo ""
echo -e "${YELLOW}📋 Comandos úteis:${NC}"
echo -e "${BLUE}Ver logs:${NC} docker compose -f docker-compose.prod.yml logs -f"
echo -e "${BLUE}Status:${NC} docker compose -f docker-compose.prod.yml ps"
echo -e "${BLUE}Reiniciar:${NC} docker compose -f docker-compose.prod.yml restart"

echo ""
echo -e "${BLUE}💡 Se ainda houver problemas, execute o diagnóstico:${NC}"
echo "./diagnose-docker-deploy.sh"
