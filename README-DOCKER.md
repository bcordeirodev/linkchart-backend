# 🐳 Ambiente Docker - Laravel Link Chart API

## 📋 Visão Geral

O projeto Laravel Link Chart API agora está totalmente configurado para rodar com Docker e Docker Compose, proporcionando um ambiente de desenvolvimento consistente e isolado.

## 🏗️ Arquitetura dos Containers

### Containers de Desenvolvimento (`docker-compose.yml`)

- **`linkchartapi-dev`**: Aplicação Laravel com PHP 8.2 + Nginx
- **`linkchartdb-dev`**: PostgreSQL 15 (banco de dados)
- **`linkchartredis-dev`**: Redis 7 (cache e sessões)

### Containers de Produção (`docker-compose.prod.yml`)

- **`linkchartapi`**: Aplicação otimizada para produção
- **`linkchartdb`**: PostgreSQL 15 (produção)
- **`linkchartredis`**: Redis 7 (produção)

## 🚀 Como Usar

### Comandos Principais (via Makefile)

```bash
# Iniciar ambiente de desenvolvimento
make up

# Parar containers
make down

# Reiniciar containers
make restart

# Verificar status
make status

# Ver logs da aplicação
make logs

# Executar shell no container
make shell

# Setup completo (iniciar + migrar + seeders)
make setup
```

### Comandos de Banco de Dados

```bash
# Executar migrações
make migrate

# Recriar banco completamente
make fresh

# Executar seeders
make seed
```

### Comandos de Manutenção

```bash
# Limpar caches
make cache-clear

# Otimizar aplicação
make optimize

# Verificar saúde da aplicação
make health

# Instalar dependências
make install
```

### Comandos de Produção

```bash
# Iniciar produção
make prod-up

# Parar produção
make prod-down

# Build produção
make prod-build

# Logs produção
make prod-logs
```

## 🌐 URLs de Acesso

### Desenvolvimento
- **API**: http://localhost:8000
- **Health Check**: http://localhost:8000/health
- **PostgreSQL**: localhost:5432
- **Redis**: localhost:6379

### Produção
- **API**: http://localhost:80
- **PostgreSQL**: localhost:5432
- **Redis**: localhost:6379

## 📁 Estrutura de Arquivos Docker

```
back-end/
├── docker/
│   ├── nginx/
│   │   ├── dev.conf          # Configuração Nginx desenvolvimento
│   │   ├── default.conf      # Configuração Nginx produção
│   │   └── nginx.conf        # Configuração principal Nginx
│   ├── php/
│   │   ├── php-dev.ini       # Configuração PHP desenvolvimento
│   │   ├── php.ini           # Configuração PHP produção
│   │   └── xdebug.ini        # Configuração Xdebug
│   ├── scripts/
│   │   └── dev-entrypoint.sh # Script de inicialização desenvolvimento
│   └── supervisor/
│       ├── supervisord.conf         # Supervisor produção
│       └── supervisord-dev.conf     # Supervisor desenvolvimento
├── docker-compose.yml        # Docker Compose desenvolvimento
├── docker-compose.prod.yml   # Docker Compose produção
├── Dockerfile                # Dockerfile produção
├── Dockerfile.dev            # Dockerfile desenvolvimento
├── Makefile                  # Comandos automatizados
└── README-DOCKER.md          # Esta documentação
```

## ⚙️ Configuração de Ambiente

### Desenvolvimento (.env.local)

```env
# Database - Docker
DB_HOST=database
DB_PORT=5432
DB_DATABASE=link_chart
DB_USERNAME=postgres
DB_PASSWORD=123456

# Redis - Docker
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
```

### Produção (.env.production)

```env
# Database - Docker
DB_HOST=database
DB_PORT=5432
DB_DATABASE=linkchartprod
DB_USERNAME=linkchartuser
DB_PASSWORD=CHANGE_ME_DB_PASSWORD

# Redis - Docker
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=CHANGE_ME_REDIS_PASSWORD
```

## 🔧 Troubleshooting

### Container não inicia

```bash
# Verificar logs
make logs

# Reconstruir containers
make build && make up
```

### Problemas de permissão

```bash
# Acessar como root
make shell-root

# Ajustar permissões
chown -R www:www /var/www/storage
chmod -R 775 /var/www/storage
```

### Banco de dados não conecta

```bash
# Verificar se PostgreSQL está funcionando
docker logs linkchartdb-dev

# Recriar banco
make down && make up
make migrate
```

### Cache/Performance

```bash
# Limpar todos os caches
make cache-clear

# Otimizar aplicação
make optimize
```

## 🛠️ Desenvolvimento

### Xdebug

O Xdebug está configurado e disponível no ambiente de desenvolvimento:

- **Host**: `host.docker.internal`
- **Porta**: `9003`
- **IDE Key**: `PHPSTORM`

### Hot Reload

O código é montado como volume, permitindo modificações em tempo real sem rebuild.

### Logs

Os logs estão disponíveis em:
- **Laravel**: `/var/www/storage/logs/laravel.log`
- **Nginx**: `/var/log/nginx/`
- **Supervisor**: `/var/log/supervisor/`

## 🚀 Deploy em Produção

### GitHub Actions

O workflow está configurado para deploy automático:

```bash
# O workflow executa automaticamente em push para main
git push origin main
```

### Deploy Manual

```bash
# No servidor de produção
cd /var/www/linkchartapi
git pull origin main
make prod-down
make prod-build
make prod-up
```

## 📊 Monitoramento

### Health Checks

- **Aplicação**: `curl http://localhost:8000/health`
- **PostgreSQL**: Configurado no Docker Compose
- **Redis**: Configurado no Docker Compose

### Métricas

```bash
# Status dos containers
make status

# Uso de recursos
docker stats

# Logs em tempo real
make logs-all
```

## 🔐 Segurança

### Variáveis de Ambiente

- Todas as senhas estão nas variáveis de ambiente
- Arquivo `.env` não é versionado
- Produção usa senhas mais complexas

### Network

- Containers isolados em rede própria
- Apenas portas necessárias expostas

## 🆘 Suporte

### Comandos Úteis

```bash
# Lista todos os comandos disponíveis
make help

# Acesso direto ao banco
docker exec -it linkchartdb-dev psql -U postgres -d link_chart

# Acesso ao Redis
docker exec -it linkchartredis-dev redis-cli

# Ver todas as rotas da API
make shell
php artisan route:list
```

### Reset Completo

```bash
# Para resetar tudo
make down
docker system prune -f
docker volume prune -f
make build
make setup
```

---

## ✅ Status Atual

- ✅ Ambiente Docker funcional
- ✅ Banco PostgreSQL dockerizado
- ✅ Redis dockerizado
- ✅ Nginx configurado
- ✅ Aplicação Laravel funcionando
- ✅ Makefile com comandos úteis
- ✅ GitHub Actions integrado
- ✅ Deploy automático funcionando 100%

**🌐 Aplicação disponível em: http://localhost:8000**
