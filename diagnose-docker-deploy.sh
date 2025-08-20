#!/bin/bash

# ==========================================
# DIAGNÓSTICO COMPLETO - DOCKER DIGITALOCEAN
# Link Chart API - Análise de Deploy
# ==========================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Função para criar separadores
separator() {
    echo ""
    echo -e "${CYAN}=====================================${NC}"
    echo -e "${CYAN}$1${NC}"
    echo -e "${CYAN}=====================================${NC}"
    echo ""
}

# Função para executar comandos com timeout
run_with_timeout() {
    timeout 30 "$@" || echo -e "${RED}⏰ Comando timeout após 30s${NC}"
}

# Criar arquivo de log
LOG_FILE="docker-diagnosis-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee -a "$LOG_FILE")
exec 2>&1

separator "🔍 DIAGNÓSTICO DOCKER - DIGITALOCEAN"

echo -e "${BLUE}📅 Data/Hora:${NC} $(date)"
echo -e "${BLUE}🖥️  Servidor:${NC} $(hostname)"
echo -e "${BLUE}👤 Usuário:${NC} $(whoami)"
echo -e "${BLUE}📂 Diretório:${NC} $(pwd)"
echo -e "${BLUE}📝 Log File:${NC} $LOG_FILE"

separator "🖥️  INFORMAÇÕES DO SISTEMA"

echo -e "${YELLOW}📊 Uso de Recursos:${NC}"
echo "CPU e Memória:"
top -bn1 | head -5

echo ""
echo "Espaço em Disco:"
df -h

echo ""
echo "Memória:"
free -h

echo ""
echo "Processos Docker:"
ps aux | grep docker | grep -v grep || echo "Nenhum processo Docker encontrado"

separator "🐳 STATUS DO DOCKER"

echo -e "${YELLOW}🔧 Versão do Docker:${NC}"
docker --version 2>/dev/null || echo -e "${RED}❌ Docker não instalado${NC}"

echo ""
echo -e "${YELLOW}📋 Docker Compose Version:${NC}"
docker compose version 2>/dev/null || docker-compose --version 2>/dev/null || echo -e "${RED}❌ Docker Compose não encontrado${NC}"

echo ""
echo -e "${YELLOW}🏃 Status do Docker Service:${NC}"
systemctl is-active docker 2>/dev/null || service docker status 2>/dev/null || echo -e "${RED}❌ Não foi possível verificar status do Docker${NC}"

echo ""
echo -e "${YELLOW}📊 Docker System Info:${NC}"
run_with_timeout docker system info 2>/dev/null || echo -e "${RED}❌ Não foi possível obter info do Docker${NC}"

echo ""
echo -e "${YELLOW}💾 Docker Disk Usage:${NC}"
run_with_timeout docker system df 2>/dev/null || echo -e "${RED}❌ Não foi possível obter uso de disco${NC}"

separator "📁 ARQUIVOS DE CONFIGURAÇÃO"

echo -e "${YELLOW}📋 Verificando arquivos essenciais:${NC}"

files_to_check=(
    ".env"
    "docker-compose.prod.yml"
    "Dockerfile"
    "composer.json"
    "artisan"
)

for file in "${files_to_check[@]}"; do
    if [[ -f "$file" ]]; then
        echo -e "${GREEN}✅ $file${NC} - $(wc -l < "$file") linhas"
    else
        echo -e "${RED}❌ $file${NC} - NÃO ENCONTRADO"
    fi
done

echo ""
echo -e "${YELLOW}🔐 Conteúdo do .env (sem senhas):${NC}"
if [[ -f ".env" ]]; then
    grep -v -E "(PASSWORD|SECRET|KEY)" .env | head -20 || echo "Erro ao ler .env"
else
    echo -e "${RED}❌ Arquivo .env não encontrado${NC}"
fi

echo ""
echo -e "${YELLOW}📦 Docker Compose Config:${NC}"
if [[ -f "docker-compose.prod.yml" ]]; then
    run_with_timeout docker compose -f docker-compose.prod.yml config 2>/dev/null || echo -e "${RED}❌ Erro na configuração do docker-compose${NC}"
else
    echo -e "${RED}❌ docker-compose.prod.yml não encontrado${NC}"
fi

separator "🐳 STATUS DOS CONTAINERS"

echo -e "${YELLOW}📊 Containers em execução:${NC}"
run_with_timeout docker ps -a || echo -e "${RED}❌ Erro ao listar containers${NC}"

echo ""
echo -e "${YELLOW}🔍 Docker Compose Status:${NC}"
if [[ -f "docker-compose.prod.yml" ]]; then
    run_with_timeout docker compose -f docker-compose.prod.yml ps || echo -e "${RED}❌ Erro ao verificar status do compose${NC}"
else
    echo -e "${RED}❌ docker-compose.prod.yml não encontrado${NC}"
fi

echo ""
echo -e "${YELLOW}📊 Estatísticas dos Containers:${NC}"
run_with_timeout docker stats --no-stream 2>/dev/null || echo -e "${RED}❌ Erro ao obter estatísticas${NC}"

separator "🔍 REDES E VOLUMES"

echo -e "${YELLOW}🌐 Redes Docker:${NC}"
run_with_timeout docker network ls || echo -e "${RED}❌ Erro ao listar redes${NC}"

echo ""
echo -e "${YELLOW}💾 Volumes Docker:${NC}"
run_with_timeout docker volume ls || echo -e "${RED}❌ Erro ao listar volumes${NC}"

echo ""
echo -e "${YELLOW}🔍 Detalhes da Rede do Projeto:${NC}"
network_name=$(docker network ls | grep linkchartnet | awk '{print $2}' | head -1)
if [[ -n "$network_name" ]]; then
    run_with_timeout docker network inspect "$network_name" || echo -e "${RED}❌ Erro ao inspecionar rede${NC}"
else
    echo -e "${YELLOW}⚠️  Rede linkchartnet não encontrada${NC}"
fi

separator "📋 LOGS DOS CONTAINERS"

containers=("linkchartapi" "linkchartdb" "linkchartredis" "linkchartnginx")

for container in "${containers[@]}"; do
    echo -e "${YELLOW}📄 Logs do $container:${NC}"
    if docker ps -a --format "table {{.Names}}" | grep -q "^$container$"; then
        echo "Últimas 50 linhas:"
        run_with_timeout docker logs --tail=50 "$container" 2>&1 || echo -e "${RED}❌ Erro ao obter logs${NC}"
    else
        echo -e "${RED}❌ Container $container não encontrado${NC}"
    fi
    echo ""
    echo "---"
    echo ""
done

echo -e "${YELLOW}📄 Logs do Docker Compose:${NC}"
if [[ -f "docker-compose.prod.yml" ]]; then
    run_with_timeout docker compose -f docker-compose.prod.yml logs --tail=50 || echo -e "${RED}❌ Erro ao obter logs do compose${NC}"
else
    echo -e "${RED}❌ docker-compose.prod.yml não encontrado${NC}"
fi

separator "🔌 TESTES DE CONECTIVIDADE"

echo -e "${YELLOW}🌐 Testando conectividade local:${NC}"

# Testar portas locais
ports=("80" "443" "5432" "6379")
for port in "${ports[@]}"; do
    if netstat -tlnp 2>/dev/null | grep -q ":$port "; then
        echo -e "${GREEN}✅ Porta $port está em uso${NC}"
    else
        echo -e "${RED}❌ Porta $port não está em uso${NC}"
    fi
done

echo ""
echo -e "${YELLOW}🏥 Health Check da API:${NC}"
for url in "http://localhost/api/health" "http://127.0.0.1/api/health" "http://138.197.121.81/api/health"; do
    echo -n "Testando $url: "
    if curl -f -s --connect-timeout 10 "$url" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ OK${NC}"
    else
        echo -e "${RED}❌ FALHOU${NC}"
    fi
done

separator "🔧 ANÁLISE DE PROBLEMAS COMUNS"

echo -e "${YELLOW}🔍 Verificando problemas comuns:${NC}"

# Verificar se há conflitos de porta
echo "1. Conflitos de Porta:"
for port in 80 443 5432 6379; do
    processes=$(netstat -tlnp 2>/dev/null | grep ":$port " | wc -l)
    if [[ $processes -gt 1 ]]; then
        echo -e "${RED}⚠️  Porta $port tem múltiplos processos${NC}"
        netstat -tlnp 2>/dev/null | grep ":$port "
    fi
done

echo ""
echo "2. Espaço em Disco:"
disk_usage=$(df / | awk 'NR==2{print $5}' | sed 's/%//')
if [[ $disk_usage -gt 90 ]]; then
    echo -e "${RED}⚠️  Disco quase cheio: ${disk_usage}%${NC}"
else
    echo -e "${GREEN}✅ Espaço em disco OK: ${disk_usage}%${NC}"
fi

echo ""
echo "3. Memória Disponível:"
mem_available=$(free | awk 'NR==2{printf "%.1f", $3*100/$2}')
if (( $(echo "$mem_available > 90" | bc -l) )); then
    echo -e "${RED}⚠️  Memória alta: ${mem_available}%${NC}"
else
    echo -e "${GREEN}✅ Memória OK: ${mem_available}%${NC}"
fi

echo ""
echo "4. Containers com Problemas:"
if docker ps -a --filter "status=exited" --filter "status=dead" --format "table {{.Names}}\t{{.Status}}" | grep -q "Exited\|Dead"; then
    echo -e "${RED}⚠️  Containers com problemas encontrados:${NC}"
    docker ps -a --filter "status=exited" --filter "status=dead" --format "table {{.Names}}\t{{.Status}}"
else
    echo -e "${GREEN}✅ Todos os containers estão rodando${NC}"
fi

separator "💡 COMANDOS PARA RESOLUÇÃO"

echo -e "${YELLOW}🔧 Comandos úteis para resolução de problemas:${NC}"

echo ""
echo -e "${BLUE}📋 Para reiniciar tudo:${NC}"
echo "docker compose -f docker-compose.prod.yml down"
echo "docker compose -f docker-compose.prod.yml up -d --build"

echo ""
echo -e "${BLUE}📋 Para limpar recursos Docker:${NC}"
echo "docker system prune -f"
echo "docker volume prune -f"
echo "docker network prune -f"

echo ""
echo -e "${BLUE}📋 Para verificar logs específicos:${NC}"
echo "docker compose -f docker-compose.prod.yml logs -f [service_name]"
echo "docker logs [container_name] --tail=100 -f"

echo ""
echo -e "${BLUE}📋 Para executar comandos dentro do container:${NC}"
echo "docker compose -f docker-compose.prod.yml exec app bash"
echo "docker compose -f docker-compose.prod.yml exec app php artisan migrate"

echo ""
echo -e "${BLUE}📋 Para testar conectividade:${NC}"
echo "curl -v http://localhost/api/health"
echo "docker compose -f docker-compose.prod.yml exec app php artisan tinker"

separator "📊 RESUMO DO DIAGNÓSTICO"

echo -e "${GREEN}✅ Diagnóstico completo salvo em: $LOG_FILE${NC}"
echo ""
echo -e "${YELLOW}📋 Próximos passos recomendados:${NC}"
echo "1. Revisar os logs dos containers com problemas"
echo "2. Verificar configurações de rede e portas"
echo "3. Testar conectividade entre containers"
echo "4. Executar comandos de resolução se necessário"
echo ""
echo -e "${BLUE}💡 Para análise detalhada, envie o arquivo: $LOG_FILE${NC}"

echo ""
echo -e "${GREEN}🎉 Diagnóstico concluído!${NC}"
