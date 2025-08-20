# 🚀 Guia de Deploy - LinkChart API

## 📋 Visão Geral

Este projeto utiliza **GitHub Actions** para deploy automático e suporta múltiplos ambientes com arquivos `.env` específicos.

### 🏗️ Estrutura de Ambientes

```
.env.local      → Desenvolvimento local
.env.staging    → Ambiente de staging  
.env.production → Ambiente de produção
```

## 🔧 Configuração Inicial

### 1. Configurar SSH no GitHub

1. Gere uma chave SSH no servidor:
```bash
ssh-keygen -t ed25519 -C "github-actions@linkchartapi.com"
```

2. Adicione a chave pública ao `~/.ssh/authorized_keys`

3. Adicione a chave privada aos **GitHub Secrets**:
   - Vá em: `Settings → Secrets and variables → Actions`
   - Adicione: `PRODUCTION_SSH_KEY` com o conteúdo da chave privada

### 2. Configurar Secrets do GitHub

Adicione estas secrets no repositório:

```
PRODUCTION_SSH_KEY           → Chave SSH privada
PRODUCTION_DB_PASSWORD       → Senha do banco de produção
PRODUCTION_REDIS_PASSWORD    → Senha do Redis
PRODUCTION_JWT_SECRET        → Secret do JWT
PRODUCTION_AWS_ACCESS_KEY    → AWS Access Key
PRODUCTION_AWS_SECRET_KEY    → AWS Secret Key
PRODUCTION_MAILGUN_USERNAME  → Username do Mailgun
PRODUCTION_MAILGUN_PASSWORD  → Password do Mailgun
```

## 🚀 Deploy Automático

### Trigger do Deploy

O deploy é executado automaticamente quando:
- ✅ Push para branch `main`
- ✅ Execução manual via GitHub Actions

### Fluxo do Deploy

1. **Checkout** do código
2. **SSH** para o servidor de produção
3. **Backup** do ambiente atual
4. **Pull** das mudanças do Git
5. **Build** dos containers Docker
6. **Restart** dos serviços
7. **Health Check** automático
8. **Rollback** em caso de falha

## 🛠️ Deploy Manual

### Usando Script de Deploy

```bash
# Deploy para produção
./scripts/deploy.sh production

# Deploy para staging
./scripts/deploy.sh staging
```

### Deploy Manual Passo a Passo

```bash
cd /var/www/linkchartapi

# 1. Backup do ambiente
cp .env .env.backup.$(date +%Y%m%d_%H%M%S)

# 2. Pull das mudanças
git pull origin main

# 3. Aplicar ambiente
cp .env.production .env

# 4. Restart containers
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d --build

# 5. Otimizações
docker exec linkchartapi php /var/www/artisan config:cache
docker exec linkchartapi php /var/www/artisan route:cache
docker exec linkchartapi php /var/www/artisan migrate --force

# 6. Health check
curl http://localhost/health
```

## 🔐 Gerenciamento de Ambientes

### Usando o Environment Manager

```bash
# Listar ambientes disponíveis
./scripts/env-manager.sh list

# Editar ambiente de produção
./scripts/env-manager.sh edit production

# Trocar para ambiente de staging
./scripts/env-manager.sh switch staging

# Fazer backup do ambiente atual
./scripts/env-manager.sh backup

# Restaurar backup
./scripts/env-manager.sh restore

# Validar ambiente
./scripts/env-manager.sh validate production
```

### Edição Manual

```bash
# Editar produção
nano .env.production

# Aplicar mudanças (copia para .env e reinicia)
./scripts/env-manager.sh switch production
```

## 🏥 Monitoramento

### Health Checks Automáticos

- ✅ **A cada 15 minutos** via GitHub Actions
- ✅ **Verifica aplicação principal** (`/health`)
- ✅ **Verifica API** (`/api/analytics`)
- ✅ **Verifica containers Docker**

### Health Check Manual

```bash
# Verificar aplicação
curl http://138.197.121.81/health

# Verificar API
curl http://138.197.121.81/api/analytics

# Verificar containers
docker ps

# Verificar logs
docker logs linkchartapi
```

## 🔄 Rollback

### Rollback Automático

O sistema faz rollback automaticamente se:
- ❌ Health check falha após deploy
- ❌ Containers não sobem corretamente
- ❌ Aplicação retorna erro 500

### Rollback Manual

```bash
# Usar backup mais recente
cp .env.backup.$(ls -t .env.backup.* | head -1) .env
docker-compose -f docker-compose.prod.yml restart

# Ou usar commit anterior
git reset --hard HEAD~1
./scripts/deploy.sh production
```

## 📊 Status Atual

- **Servidor:** 138.197.121.81 (DigitalOcean)
- **Ambiente:** Production
- **Containers:** 4 (app, db, redis, nginx)
- **Deploy:** Automático via GitHub Actions
- **Monitoramento:** Health checks a cada 15min

## 🆘 Troubleshooting

### Problemas Comuns

**1. Deploy falha com erro SSH**
```bash
# Verificar chave SSH
ssh -T root@138.197.121.81

# Recriar chave se necessário
ssh-keygen -t ed25519 -C "github-actions"
```

**2. Containers não sobem**
```bash
# Verificar logs
docker-compose -f docker-compose.prod.yml logs

# Rebuild completo
docker-compose -f docker-compose.prod.yml down
docker system prune -f
docker-compose -f docker-compose.prod.yml up -d --build
```

**3. Aplicação retorna 500**
```bash
# Verificar logs Laravel
docker exec linkchartapi tail -f /var/www/storage/logs/laravel.log

# Limpar caches
docker exec linkchartapi php /var/www/artisan config:clear
docker exec linkchartapi php /var/www/artisan cache:clear
```

**4. Banco de dados não conecta**
```bash
# Verificar container do banco
docker logs linkchartdb

# Verificar configurações
./scripts/env-manager.sh validate production
```

## 📞 Suporte

Para problemas críticos:
1. Verificar **GitHub Actions** para logs detalhados
2. Acessar servidor via SSH: `ssh root@138.197.121.81`
3. Executar health checks manuais
4. Consultar logs dos containers

---

**✨ Deploy automatizado e monitorado 24/7!**
