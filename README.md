# 🔗 Link Chart - Backend API

Backend da aplicação Link Chart desenvolvido em Laravel 12 com PHP 8.2.

## 🚀 Tecnologias

- **PHP 8.2+**
- **Laravel 12**
- **PostgreSQL 15**
- **Redis 7**
- **Docker & Docker Compose**
- **Nginx**

## 📦 Instalação Local

```bash
# Clonar repositório
git clone git@github.com:bcordeirodev/linkchart-backend.git
cd linkchart-backend

# Copiar configurações
cp .env.example .env

# Instalar dependências
composer install

# Gerar chave da aplicação
php artisan key:generate

# Executar migrações
php artisan migrate

# Iniciar servidor
php artisan serve
```

## 🐳 Deploy com Docker

```bash
# Produção
docker-compose -f docker-compose.prod.yml up -d --build

# Executar migrações
docker-compose exec app php artisan migrate --force

# Otimizar para produção
docker-compose exec app php artisan optimize
```

## 🌐 Deploy no DigitalOcean

1. Criar droplet Ubuntu 22.04
2. Instalar Docker e Docker Compose
3. Clonar este repositório
4. Configurar `.env.production`
5. Executar `./deploy.sh`

Ver `DEPLOYMENT_GUIDE.md` para instruções completas.

## 📊 Funcionalidades

- ✅ Encurtamento de URLs
- ✅ Analytics avançados
- ✅ Autenticação JWT
- ✅ Rate limiting
- ✅ Cache Redis
- ✅ Geolocalização
- ✅ API RESTful

## 🔧 Comandos Úteis

```bash
# Limpar cache
php artisan optimize:clear

# Executar workers
php artisan queue:work

# Executar testes
php artisan test

# Verificar saúde
curl http://localhost:8000/api/health
```

## 📝 Documentação da API

Acesse `/api/documentation` para ver a documentação completa da API.

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature
3. Commit suas mudanças
4. Push para a branch
5. Abra um Pull Request

## 📄 Licença

Este projeto está sob a licença MIT.
# Deploy trigger sáb 23 ago 2025 14:05:15 -03
