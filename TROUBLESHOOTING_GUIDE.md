# 🔧 **GUIA DE TROUBLESHOOTING - DOCKER DIGITALOCEAN**

## 📋 **INSTRUÇÕES PASSO A PASSO**

### **🚨 SITUAÇÃO ATUAL**
Você está conectado via SSH na DigitalOcean (`138.197.121.81`) e precisa diagnosticar problemas no Docker.

---

## **📝 PASSO 1: EXECUTAR DIAGNÓSTICO COMPLETO**

### **Opção A: Executar Remotamente (do seu computador)**
```bash
# No seu computador local
cd /home/bruno/projects/link-chart/back-end
./run-diagnosis-remotely.sh
```

### **Opção B: Executar Diretamente no Servidor**
```bash
# Conectar no servidor
ssh root@138.197.121.81

# Ir para o diretório da aplicação
cd /var/www/linkchartapi

# Baixar e executar diagnóstico
curl -O https://raw.githubusercontent.com/bcordeirodev/linkchart-backend/main/diagnose-docker-deploy.sh
chmod +x diagnose-docker-deploy.sh
./diagnose-docker-deploy.sh
```

---

## **🔍 PASSO 2: ANALISAR OS RESULTADOS**

O diagnóstico vai gerar um arquivo `docker-diagnosis-YYYYMMDD-HHMMSS.log` com:

### **✅ Verificações Importantes:**
- Status do Docker e containers
- Uso de recursos (CPU, memória, disco)
- Configurações de rede e portas
- Logs detalhados de todos os serviços
- Testes de conectividade

### **🚨 Problemas Comuns a Procurar:**
1. **Containers parados** (`Status: Exited`)
2. **Portas em conflito** (80, 443, 5432, 6379)
3. **Falta de memória** (>90% uso)
4. **Disco cheio** (>90% uso)
5. **Erros nos logs** (conexão DB, Redis, etc.)

---

## **🛠️ PASSO 3: CORREÇÕES RÁPIDAS**

### **Correção Automática:**
```bash
# No servidor (como root ou linkchartapp)
cd /var/www/linkchartapi
./quick-fix-docker.sh
```

### **Correções Manuais:**

#### **🐳 Reiniciar Containers**
```bash
cd /var/www/linkchartapi
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d --build
```

#### **🧹 Limpar Recursos**
```bash
docker system prune -f
docker volume prune -f
docker network prune -f
```

#### **📊 Verificar Status**
```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f
```

---

## **🔍 PASSO 4: DIAGNÓSTICOS ESPECÍFICOS**

### **🔴 Container da Aplicação (linkchartapi)**
```bash
# Ver logs
docker logs linkchartapi --tail=50 -f

# Entrar no container
docker exec -it linkchartapi bash

# Dentro do container:
php artisan migrate:status
php artisan config:cache
php artisan optimize
```

### **🗄️ Container do Banco (linkchartdb)**
```bash
# Ver logs
docker logs linkchartdb --tail=50

# Testar conexão
docker exec -it linkchartdb psql -U linkchartuser -d linkchartprod -c "SELECT version();"
```

### **🔴 Container Redis (linkchartredis)**
```bash
# Ver logs
docker logs linkchartredis --tail=50

# Testar conexão
docker exec -it linkchartredis redis-cli ping
```

### **🌐 Container Nginx (linkchartnginx)**
```bash
# Ver logs
docker logs linkchartnginx --tail=50

# Verificar configuração
docker exec -it linkchartnginx nginx -t
```

---

## **🌐 PASSO 5: TESTES DE CONECTIVIDADE**

### **🏥 Health Checks**
```bash
# Teste local
curl -v http://localhost/api/health

# Teste externo
curl -v http://138.197.121.81/api/health

# Teste com timeout
timeout 10 curl http://138.197.121.81/api/health
```

### **🔌 Verificar Portas**
```bash
# Portas em uso
netstat -tlnp | grep -E ":(80|443|5432|6379)"

# Processos nas portas
lsof -i :80
lsof -i :5432
```

---

## **📊 PASSO 6: MONITORAMENTO EM TEMPO REAL**

### **📈 Recursos do Sistema**
```bash
# CPU e memória
htop

# Espaço em disco
df -h

# Logs em tempo real
tail -f /var/log/syslog
```

### **🐳 Docker em Tempo Real**
```bash
# Stats dos containers
docker stats

# Logs em tempo real
docker compose -f docker-compose.prod.yml logs -f

# Eventos do Docker
docker events
```

---

## **🚨 PROBLEMAS ESPECÍFICOS E SOLUÇÕES**

### **❌ Problema: "Container keeps restarting"**
```bash
# Ver por que está reiniciando
docker logs linkchartapi --tail=100

# Verificar recursos
docker stats --no-stream

# Possível solução: aumentar memória ou corrigir configuração
```

### **❌ Problema: "Port already in use"**
```bash
# Identificar processo na porta
sudo lsof -i :80

# Parar processo conflitante
sudo kill -9 <PID>

# Ou alterar porta no docker-compose.prod.yml
```

### **❌ Problema: "Database connection failed"**
```bash
# Verificar se PostgreSQL está rodando
docker ps | grep postgres

# Testar conexão
docker exec -it linkchartdb pg_isready

# Verificar variáveis de ambiente
grep DB_ .env
```

### **❌ Problema: "Redis connection failed"**
```bash
# Verificar se Redis está rodando
docker ps | grep redis

# Testar conexão
docker exec -it linkchartredis redis-cli ping

# Verificar senha
grep REDIS_PASSWORD .env
```

---

## **📋 CHECKLIST DE VERIFICAÇÃO**

### **✅ Antes de Reportar Problemas:**
- [ ] Docker está instalado e rodando
- [ ] Arquivo `.env` existe e está configurado
- [ ] Todos os containers estão em status "Up"
- [ ] Portas não estão em conflito
- [ ] Há espaço suficiente em disco (>10% livre)
- [ ] Há memória suficiente (<90% uso)
- [ ] API responde em `http://localhost/api/health`
- [ ] Logs não mostram erros críticos

---

## **📞 INFORMAÇÕES PARA SUPORTE**

### **📋 Dados Essenciais:**
1. **Arquivo de diagnóstico:** `docker-diagnosis-*.log`
2. **Status dos containers:** `docker compose -f docker-compose.prod.yml ps`
3. **Logs recentes:** `docker compose -f docker-compose.prod.yml logs --tail=100`
4. **Uso de recursos:** `df -h && free -h`
5. **Erro específico** que está ocorrendo

### **🔗 URLs de Teste:**
- **API Health:** http://138.197.121.81/api/health
- **API Base:** http://138.197.121.81/api
- **Database:** 138.197.121.81:5432
- **Redis:** 138.197.121.81:6379

---

## **🎯 COMANDOS DE EMERGÊNCIA**

### **🚨 Reset Completo (CUIDADO!):**
```bash
# ATENÇÃO: Isso vai apagar todos os dados!
cd /var/www/linkchartapi
docker compose -f docker-compose.prod.yml down --volumes
docker system prune -af
docker compose -f docker-compose.prod.yml up -d --build
```

### **🔄 Reinício Suave:**
```bash
cd /var/www/linkchartapi
docker compose -f docker-compose.prod.yml restart
```

### **📊 Status Rápido:**
```bash
cd /var/www/linkchartapi
docker compose -f docker-compose.prod.yml ps
curl -s http://localhost/api/health | echo "API: $(cat)"
```

---

## **💡 DICAS IMPORTANTES**

1. **Sempre execute comandos no diretório:** `/var/www/linkchartapi`
2. **Use o usuário correto:** `linkchartapp` para aplicação, `root` para Docker
3. **Aguarde containers ficarem prontos:** Pode levar 1-2 minutos
4. **Verifique logs sempre:** Eles contêm informações valiosas
5. **Mantenha backups:** Especialmente do `.env` e dados do banco

---

**🚀 Boa sorte com o troubleshooting!**
