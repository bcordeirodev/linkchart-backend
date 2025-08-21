# 🔍 GUIA DE ANÁLISE DE LOGS EM PRODUÇÃO - LINK CHART API

## 📋 VISÃO GERAL

Este guia fornece instruções sistemáticas para análise de problemas em produção, focando em variáveis de ambiente e configurações que podem estar causando erros 500.

**Servidor:** 138.197.121.81  
**Containers:** linkchartapi, linkchartdb, linkchartredis, linkchartnginx  
**Deploy via:** GitHub Actions workflow

---

## 🚨 PROBLEMAS IDENTIFICADOS NA ANÁLISE

### ⚠️ PROBLEMAS POTENCIAIS COM VARIÁVEIS DE AMBIENTE:

1. **JWT_SECRET pode não estar sendo carregado** (detectado anteriormente)
2. **Configurações de cache podem estar conflitando**
3. **Variáveis hardcoded no docker-compose.prod.yml**
4. **Possível conflito entre LOG_CHANNEL e configurações**

---

## 🔧 COMANDOS DE DIAGNÓSTICO SISTEMÁTICO

### 1. 📊 STATUS GERAL DO SISTEMA

```bash
# Verificar se todos os containers estão rodando
ssh root@138.197.121.81 "docker ps --filter 'name=linkchart' --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'"

# Verificar se o diretório e arquivos estão corretos
ssh root@138.197.121.81 "cd /var/www/linkchartapi && ls -la .env*"

# Verificar se o .env foi copiado corretamente do .env.production
ssh root@138.197.121.81 "cd /var/www/linkchartapi && diff .env .env.production || echo 'Arquivos diferentes ou um não existe'"
```

### 2. 🔍 ANÁLISE ESPECÍFICA DE VARIÁVEIS DE AMBIENTE

```bash
# Verificar se o arquivo .env está sendo montado no container
ssh root@138.197.121.81 "docker exec linkchartapi ls -la /var/www/.env"

# Verificar permissões do arquivo .env
ssh root@138.197.121.81 "docker exec linkchartapi stat /var/www/.env"

# Comparar conteúdo do .env no host vs container
ssh root@138.197.121.81 "cd /var/www/linkchartapi && head -10 .env"
ssh root@138.197.121.81 "docker exec linkchartapi head -10 /var/www/.env"

# Verificar se as variáveis críticas estão sendo lidas
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show app.env"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show app.debug"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show logging.default"
```

### 3. 🔐 ANÁLISE ESPECÍFICA DO JWT

```bash
# Verificar se JWT_SECRET está correto no arquivo
ssh root@138.197.121.81 "cd /var/www/linkchartapi && grep 'JWT_SECRET=' .env"

# Verificar se JWT_SECRET está sendo carregado pelo Laravel
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show jwt.secret"

# Testar geração de token JWT
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan tinker --execute=\"try { \\$token = JWTAuth::attempt(['email' => 'test@test.com', 'password' => 'test']); echo 'JWT OK'; } catch(Exception \\$e) { echo 'JWT ERROR: ' . \\$e->getMessage(); }\""
```

### 4. 🗄️ ANÁLISE DE CONEXÕES DE BANCO E REDIS

```bash
# Testar conexão com PostgreSQL
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan tinker --execute=\"try { DB::connection()->getPdo(); echo 'Database: CONNECTED'; } catch(Exception \\$e) { echo 'Database ERROR: ' . \\$e->getMessage(); }\""

# Verificar se as variáveis de banco estão corretas
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show database.connections.pgsql"

# Testar conexão com Redis
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan tinker --execute=\"try { Cache::store('redis')->put('test', 'ok'); echo 'Redis: CONNECTED'; } catch(Exception \\$e) { echo 'Redis ERROR: ' . \\$e->getMessage(); }\""

# Verificar configurações do Redis
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show cache.stores.redis"
```

### 5. 📄 ANÁLISE DETALHADA DE LOGS

```bash
# Listar todos os arquivos de log
ssh root@138.197.121.81 "docker exec linkchartapi ls -la /var/www/storage/logs/"

# Ver logs de erro mais recentes (últimas 50 linhas)
ssh root@138.197.121.81 "docker exec linkchartapi tail -50 /var/www/storage/logs/laravel-$(date +%Y-%m-%d).log | grep -i error"

# Ver logs de API específicos (se existirem)
ssh root@138.197.121.81 "docker exec linkchartapi tail -30 /var/www/storage/logs/api-errors-$(date +%Y-%m-%d).log" 2>/dev/null || echo "Log de API não existe"

# Ver logs em tempo real (para monitorar durante testes)
ssh root@138.197.121.81 "docker exec linkchartapi tail -f /var/www/storage/logs/laravel-$(date +%Y-%m-%d).log" &
```

### 6. 🧪 TESTES ESPECÍFICOS DE ENDPOINTS

```bash
# Testar health check (deve funcionar)
curl -v http://138.197.121.81/health

# Testar endpoint de registro (onde está o problema)
curl -X POST http://138.197.121.81/api/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password123","password_confirmation":"password123"}' \
  -v

# Testar endpoint simples de API
curl -v http://138.197.121.81/api/links -H "Accept: application/json"
```

---

## 🔍 PROBLEMAS ESPECÍFICOS IDENTIFICADOS

### ❌ PROBLEMA 1: LOG_CHANNEL Configuration Conflict

**Sintoma:** Erro 500 em todos os endpoints  
**Causa Provável:** `LOG_CHANNEL=production` pode não existir ou estar mal configurado

**Verificação:**
```bash
# Verificar se o canal 'production' existe
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show logging.channels.production"

# Se der erro, o canal não existe. Verificar configuração atual:
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show logging.channels"
```

**Solução Temporária:**
```bash
# Alterar para canal que sabemos que funciona
ssh root@138.197.121.81 "cd /var/www/linkchartapi && sed -i 's/LOG_CHANNEL=production/LOG_CHANNEL=daily/' .env"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:clear"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:cache"
```

### ❌ PROBLEMA 2: Environment Variables Override

**Sintoma:** Configurações não aplicadas corretamente  
**Causa Provável:** docker-compose.prod.yml sobrescreve algumas variáveis

**Verificação:**
```bash
# Verificar se variáveis hardcoded no docker-compose estão conflitando
ssh root@138.197.121.81 "cd /var/www/linkchartapi && grep -A 20 'environment:' docker-compose.prod.yml"

# Verificar qual valor o Laravel está vendo
ssh root@138.197.121.81 "docker exec linkchartapi env | grep APP_"
```

### ❌ PROBLEMA 3: Cache Configuration Issues

**Sintoma:** JWT ou outras configurações não carregam  
**Causa Provável:** Cache corrompido ou configurações conflitantes

**Verificação e Limpeza:**
```bash
# Limpar todos os caches
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan cache:clear"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:clear"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan route:clear"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan view:clear"

# Recriar caches
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:cache"
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan route:cache"
```

---

## 🔧 SEQUÊNCIA DE TROUBLESHOOTING RECOMENDADA

### FASE 1: Verificação Básica
```bash
# 1. Status dos containers
ssh root@138.197.121.81 "docker ps --filter 'name=linkchart'"

# 2. Arquivo .env existe e está correto
ssh root@138.197.121.81 "cd /var/www/linkchartapi && ls -la .env && head -5 .env"

# 3. Teste de conectividade básica
curl -I http://138.197.121.81/health
```

### FASE 2: Diagnóstico de Configuração
```bash
# 1. Verificar LOG_CHANNEL
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show logging.default"

# 2. Verificar JWT
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:show jwt.secret | head -c 50"

# 3. Verificar conexões
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan tinker --execute=\"DB::connection()->getPdo(); echo 'DB OK';\""
```

### FASE 3: Análise de Logs
```bash
# 1. Logs de erro mais recentes
ssh root@138.197.121.81 "docker exec linkchartapi tail -50 /var/www/storage/logs/laravel-$(date +%Y-%m-%d).log"

# 2. Logs durante teste de endpoint
ssh root@138.197.121.81 "docker exec linkchartapi tail -f /var/www/storage/logs/laravel-$(date +%Y-%m-%d).log" &
curl -X POST http://138.197.121.81/api/auth/register -H "Content-Type: application/json" -d '{"name":"Test","email":"test@example.com","password":"password123","password_confirmation":"password123"}'
```

### FASE 4: Correções Rápidas
```bash
# Se LOG_CHANNEL for o problema:
ssh root@138.197.121.81 "cd /var/www/linkchartapi && sed -i 's/LOG_CHANNEL=production/LOG_CHANNEL=daily/' .env"

# Limpar e recriar caches
ssh root@138.197.121.81 "docker exec linkchartapi php /var/www/artisan config:clear && php /var/www/artisan config:cache"

# Reiniciar container se necessário
ssh root@138.197.121.81 "cd /var/www/linkchartapi && docker-compose -f docker-compose.prod.yml restart app"
```

---

## 📊 ENDPOINTS DE DIAGNÓSTICO PERSONALIZADO

### Após autenticação funcionar, usar estes endpoints:

```bash
# Obter token primeiro (quando auth funcionar)
TOKEN=$(curl -s -X POST http://138.197.121.81/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test","email":"test@example.com","password":"password123","password_confirmation":"password123"}' \
  | jq -r '.token // empty')

# Diagnóstico completo
curl -H "Authorization: Bearer $TOKEN" http://138.197.121.81/api/logs/diagnostic

# Erros recentes
curl -H "Authorization: Bearer $TOKEN" http://138.197.121.81/api/logs/recent-errors

# Lista de logs
curl -H "Authorization: Bearer $TOKEN" http://138.197.121.81/api/logs/
```

---

## 🎯 CORREÇÕES MAIS PROVÁVEIS

### 1. Corrigir LOG_CHANNEL no .env.production
```env
# Mudar de:
LOG_CHANNEL=production

# Para:
LOG_CHANNEL=daily
```

### 2. Garantir que JWT_SECRET seja aplicado após cache clear
```bash
# No workflow, após copiar .env.production
docker exec linkchartapi php /var/www/artisan config:clear
docker exec linkchartapi php /var/www/artisan config:cache
```

### 3. Verificar se volumes estão montados corretamente
```yaml
# No docker-compose.prod.yml, verificar:
volumes:
  - ./.env:/var/www/.env  # Este volume deve estar correto
```

---

## 🚀 SCRIPT DE DIAGNÓSTICO RÁPIDO

Execute este comando para diagnóstico completo:

```bash
# Diagnóstico completo automatizado
ssh root@138.197.121.81 "cd /var/www/linkchartapi && ./diagnose-logs.sh"
```

---

## 📋 CHECKLIST DE VERIFICAÇÃO

- [ ] Containers todos rodando (linkchartapi, linkchartdb, linkchartredis, linkchartnginx)
- [ ] Arquivo .env existe no host e no container
- [ ] LOG_CHANNEL está configurado para um canal que existe
- [ ] JWT_SECRET tem mais de 256 bits e está sendo carregado
- [ ] Conexão com PostgreSQL funcionando
- [ ] Conexão com Redis funcionando
- [ ] Logs mostram erros específicos (não genéricos)
- [ ] Cache foi limpo após mudanças de configuração

---

**🔍 Este guia deve ser seguido sequencialmente para identificar e resolver problemas de variáveis de ambiente em produção.**
