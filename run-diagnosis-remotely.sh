#!/bin/bash

# ==========================================
# EXECUTAR DIAGNÓSTICO REMOTO - DIGITALOCEAN
# Link Chart API - Execução via SSH
# ==========================================

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configurações do servidor
SERVER_IP="138.197.121.81"
SERVER_USER="root"
APP_USER="linkchartapp"
APP_DIR="/var/www/linkchartapi"

echo -e "${BLUE}🚀 Executando diagnóstico remoto na DigitalOcean...${NC}"
echo -e "${YELLOW}Servidor: $SERVER_IP${NC}"
echo ""

# Função para executar comandos via SSH
run_ssh() {
    local user=$1
    local command=$2
    echo -e "${BLUE}🔧 Executando como $user:${NC} $command"
    ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no "$user@$SERVER_IP" "$command"
}

# Função para copiar arquivo via SCP
copy_file() {
    local local_file=$1
    local remote_path=$2
    local user=$3
    echo -e "${BLUE}📤 Copiando $local_file para $user@$SERVER_IP:$remote_path${NC}"
    scp -o ConnectTimeout=10 -o StrictHostKeyChecking=no "$local_file" "$user@$SERVER_IP:$remote_path"
}

# Verificar conectividade SSH
echo -e "${BLUE}🔍 Testando conectividade SSH...${NC}"
if ! ssh -o ConnectTimeout=5 -o BatchMode=yes "$SERVER_USER@$SERVER_IP" exit 2>/dev/null; then
    echo -e "${RED}❌ Não foi possível conectar via SSH${NC}"
    echo -e "${YELLOW}💡 Certifique-se de que:${NC}"
    echo "1. O servidor está ligado"
    echo "2. SSH está configurado"
    echo "3. Suas chaves SSH estão configuradas"
    echo ""
    echo -e "${YELLOW}🔧 Para conectar manualmente:${NC}"
    echo "ssh $SERVER_USER@$SERVER_IP"
    exit 1
fi

echo -e "${GREEN}✅ Conectividade SSH OK!${NC}"

# ==========================================
# EXECUTAR DIAGNÓSTICO COMO ROOT
# ==========================================

echo ""
echo -e "${BLUE}🔍 Executando diagnóstico básico como root...${NC}"

# Verificar sistema
run_ssh "$SERVER_USER" "echo '=== INFORMAÇÕES DO SISTEMA ===' && \
    uname -a && \
    echo '' && \
    echo '=== DOCKER STATUS ===' && \
    systemctl is-active docker && \
    docker --version && \
    echo '' && \
    echo '=== CONTAINERS RODANDO ===' && \
    docker ps -a && \
    echo '' && \
    echo '=== PORTAS EM USO ===' && \
    netstat -tlnp | grep -E ':(80|443|5432|6379) ' && \
    echo '' && \
    echo '=== RECURSOS DO SISTEMA ===' && \
    free -h && \
    df -h"

# ==========================================
# COPIAR E EXECUTAR SCRIPT DE DIAGNÓSTICO
# ==========================================

echo ""
echo -e "${BLUE}📤 Copiando script de diagnóstico...${NC}"

# Copiar script como root primeiro
copy_file "diagnose-docker-deploy.sh" "/tmp/diagnose-docker-deploy.sh" "$SERVER_USER"

# Tornar executável
run_ssh "$SERVER_USER" "chmod +x /tmp/diagnose-docker-deploy.sh"

# Verificar se usuário da aplicação existe
echo ""
echo -e "${BLUE}👤 Verificando usuário da aplicação...${NC}"
if run_ssh "$SERVER_USER" "id $APP_USER" 2>/dev/null; then
    echo -e "${GREEN}✅ Usuário $APP_USER existe${NC}"

    # Verificar se diretório da aplicação existe
    if run_ssh "$SERVER_USER" "test -d $APP_DIR"; then
        echo -e "${GREEN}✅ Diretório $APP_DIR existe${NC}"

        # Copiar script para diretório da aplicação
        run_ssh "$SERVER_USER" "cp /tmp/diagnose-docker-deploy.sh $APP_DIR/ && chown $APP_USER:$APP_USER $APP_DIR/diagnose-docker-deploy.sh"

        # Executar diagnóstico como usuário da aplicação
        echo ""
        echo -e "${BLUE}🔍 Executando diagnóstico completo como $APP_USER...${NC}"
        run_ssh "$APP_USER" "cd $APP_DIR && ./diagnose-docker-deploy.sh"

    else
        echo -e "${RED}❌ Diretório $APP_DIR não existe${NC}"
        echo -e "${YELLOW}💡 Executando diagnóstico como root...${NC}"
        run_ssh "$SERVER_USER" "cd /tmp && ./diagnose-docker-deploy.sh"
    fi
else
    echo -e "${RED}❌ Usuário $APP_USER não existe${NC}"
    echo -e "${YELLOW}💡 Executando diagnóstico como root...${NC}"
    run_ssh "$SERVER_USER" "cd /tmp && ./diagnose-docker-deploy.sh"
fi

# ==========================================
# BAIXAR LOGS GERADOS
# ==========================================

echo ""
echo -e "${BLUE}📥 Baixando logs de diagnóstico...${NC}"

# Tentar baixar do diretório da aplicação primeiro
if run_ssh "$SERVER_USER" "test -d $APP_DIR"; then
    log_files=$(run_ssh "$SERVER_USER" "find $APP_DIR -name 'docker-diagnosis-*.log' -type f" 2>/dev/null || echo "")
    if [[ -n "$log_files" ]]; then
        for log_file in $log_files; do
            local_log_file="$(basename "$log_file")"
            echo -e "${BLUE}📥 Baixando $log_file...${NC}"
            scp -o ConnectTimeout=10 -o StrictHostKeyChecking=no "$SERVER_USER@$SERVER_IP:$log_file" "./$local_log_file"
            if [[ -f "./$local_log_file" ]]; then
                echo -e "${GREEN}✅ Log salvo em: ./$local_log_file${NC}"
            fi
        done
    fi
fi

# Tentar baixar do /tmp também
log_files=$(run_ssh "$SERVER_USER" "find /tmp -name 'docker-diagnosis-*.log' -type f" 2>/dev/null || echo "")
if [[ -n "$log_files" ]]; then
    for log_file in $log_files; do
        local_log_file="tmp-$(basename "$log_file")"
        echo -e "${BLUE}📥 Baixando $log_file...${NC}"
        scp -o ConnectTimeout=10 -o StrictHostKeyChecking=no "$SERVER_USER@$SERVER_IP:$log_file" "./$local_log_file"
        if [[ -f "./$local_log_file" ]]; then
            echo -e "${GREEN}✅ Log salvo em: ./$local_log_file${NC}"
        fi
    done
fi

# ==========================================
# COMANDOS RÁPIDOS DE RESOLUÇÃO
# ==========================================

echo ""
echo -e "${BLUE}🔧 Executando comandos rápidos de resolução...${NC}"

# Verificar se há containers parados
echo ""
echo -e "${YELLOW}🔍 Verificando containers parados...${NC}"
stopped_containers=$(run_ssh "$SERVER_USER" "docker ps -a --filter 'status=exited' --format '{{.Names}}'" 2>/dev/null || echo "")

if [[ -n "$stopped_containers" ]]; then
    echo -e "${RED}⚠️  Containers parados encontrados:${NC}"
    echo "$stopped_containers"

    echo ""
    echo -e "${YELLOW}🔧 Tentando reiniciar containers...${NC}"
    if run_ssh "$SERVER_USER" "test -d $APP_DIR"; then
        run_ssh "$APP_USER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml up -d" || \
        run_ssh "$SERVER_USER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml up -d"
    fi
else
    echo -e "${GREEN}✅ Todos os containers estão rodando${NC}"
fi

# Testar API
echo ""
echo -e "${YELLOW}🌐 Testando API...${NC}"
api_status=$(run_ssh "$SERVER_USER" "curl -f -s --connect-timeout 5 http://localhost/api/health" 2>/dev/null || echo "FAILED")

if [[ "$api_status" != "FAILED" ]]; then
    echo -e "${GREEN}✅ API está respondendo${NC}"
else
    echo -e "${RED}❌ API não está respondendo${NC}"
    echo -e "${YELLOW}💡 Verificando logs da aplicação...${NC}"
    run_ssh "$SERVER_USER" "docker logs linkchartapi --tail=20" 2>/dev/null || echo "Não foi possível obter logs"
fi

# ==========================================
# RESUMO E PRÓXIMOS PASSOS
# ==========================================

echo ""
echo -e "${GREEN}🎉 Diagnóstico remoto concluído!${NC}"
echo ""
echo -e "${YELLOW}📋 Arquivos gerados localmente:${NC}"
ls -la docker-diagnosis-*.log tmp-docker-diagnosis-*.log 2>/dev/null || echo "Nenhum arquivo de log baixado"

echo ""
echo -e "${YELLOW}🔧 Para conectar manualmente ao servidor:${NC}"
echo "ssh $SERVER_USER@$SERVER_IP"

echo ""
echo -e "${YELLOW}🔧 Para acessar a aplicação:${NC}"
echo "ssh $APP_USER@$SERVER_IP"
echo "cd $APP_DIR"

echo ""
echo -e "${YELLOW}🔧 Para ver logs em tempo real:${NC}"
echo "ssh $SERVER_USER@$SERVER_IP 'cd $APP_DIR && docker compose -f docker-compose.prod.yml logs -f'"

echo ""
echo -e "${YELLOW}🌐 URLs para testar:${NC}"
echo "http://$SERVER_IP/api/health"
echo "http://$SERVER_IP"

echo ""
echo -e "${BLUE}💡 Se precisar de ajuda, compartilhe os logs gerados!${NC}"
