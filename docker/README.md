# 🐳 Docker Configuration - Link Chart Backend

## 📂 Estrutura de Arquivos - DEV vs PROD

### 🔧 **DESENVOLVIMENTO (Local)**

#### Dockerfile
- `Dockerfile.dev` - Container de desenvolvimento com Xdebug, hot reload

#### PHP Configuration
- `php/php-dev.ini` - Configuração PHP para desenvolvimento
  - `memory_limit = 512M` (mais liberal)
  - `display_errors = On`
  - `error_reporting = E_ALL`
  - `max_execution_time = 300` (extenso para debug)
- `php/opcache-dev.ini` - OPcache para desenvolvimento
  - `opcache.validate_timestamps = 1` (revalidação ativa)
  - `opcache.revalidate_freq = 2` (check a cada 2s)
  - Logs de debug habilitados
- `php/xdebug.ini` - Configuração específica do Xdebug

#### Nginx Configuration  
- `nginx/dev.conf` - Nginx para desenvolvimento
  - Logs em modo debug
  - Timeouts mais longos para debug
  - Configurações menos restritivas
  - CORS gerenciado pelo Laravel

#### Supervisor Configuration
- `supervisor/supervisord-dev.conf` - Processos para desenvolvimento
  - 2 workers de queue (menos recursos)
  - User: `www-data`
  - Logs detalhados

#### Scripts
- `scripts/dev-entrypoint.sh` - Script de inicialização para desenvolvimento

---

### 🚀 **PRODUÇÃO (Server)**

#### Dockerfile
- `Dockerfile` - Container otimizado para produção, sem Xdebug

#### PHP Configuration
- `php/php-prod.ini` - Configuração PHP otimizada para produção
  - `memory_limit = 256M` (otimizado)
  - `display_errors = Off`
  - `log_errors = On`
  - `max_execution_time = 60`
  - OPcache habilitado
  - Session via Redis
- `php/opcache-prod.ini` - OPcache otimizado para produção
  - `opcache.validate_timestamps = 0` (performance máxima)
  - `opcache.memory_consumption = 256`
  - `opcache.jit_buffer_size = 128M`

#### Nginx Configuration
- `nginx/prod.conf` - Nginx para produção
  - Security headers
  - Cache agressivo para assets
  - Compressão gzip
  - Bloqueio de arquivos/diretórios sensíveis
  - Timeouts otimizados
- `nginx/nginx.conf` - Configuração global do Nginx

#### Supervisor Configuration  
- `supervisor/supervisord-prod.conf` - Processos para produção
  - 4 workers de queue (mais performance)
  - User: `www`
  - Laravel Schedule incluso
  - Logs otimizados

---

## 🔄 **Docker Compose Files**

### Development
```yaml
# docker-compose.yml
services:
  app:
    dockerfile: Dockerfile.dev
    volumes: # Code hot reload
    environment: # Dev vars
    ports: ["8000:80"] # Port mapping
```

### Production  
```yaml
# docker-compose.prod.yml
services:
  app:
    dockerfile: Dockerfile
    volumes: # Only logs/storage
    environment: # Prod vars
    healthchecks: # Robust monitoring
```

---

## 📋 **Environment Files**

### Development
- `.env.local` - Variáveis locais
- `.env` - Fallback (dev)

### Production
- `.env.production` - Configuração base de produção
- `.env` - Gerado pelo workflow (prod + secrets)

---

## 🛠️ **Como Usar**

### Desenvolvimento Local
```bash
# Build e start dev
make build && make up

# Logs
make logs

# Shell 
make shell
```

### Produção (GitHub Actions)
```bash
# Deploy automático via push main
git push origin main

# Deploy manual
gh workflow run "Deploy to Production"
```

---

## 🎯 **Diferenças Principais**

| Aspecto | Desenvolvimento | Produção |
|---------|----------------|----------|
| **Performance** | Debug-friendly | Otimizada |
| **Security** | Permissiva | Restritiva |
| **Logging** | Detalhado | Otimizado |
| **Caching** | Desabilitado | Máximo |
| **Error Display** | Visível | Oculto |
| **Resources** | Liberal | Limitado |
| **Xdebug** | ✅ Habilitado | ❌ Desabilitado |
| **OPcache** | ⚠️ Básico | ✅ Otimizado |
| **Volumes** | Code Mount | Apenas dados |
| **Health Checks** | Básico | Completo |

---

## ⚡ **Troubleshooting**

### Development Issues
```bash
# Rebuild containers
make build

# Reset permissions  
make shell-root
chown -R www-data:www-data /var/www/storage

# Check logs
make logs-all
```

### Production Issues
```bash
# Via SSH
ssh root@138.197.121.81
cd /var/www/linkchartapi

# Check containers
docker compose -f docker-compose.prod.yml ps

# Check logs
docker logs linkchartapi
```

---

**📌 Nota:** Todos os arquivos `-dev` são para desenvolvimento local, arquivos `-prod` são para produção no servidor.
