# ==============================================
# MAKEFILE - AMBIENTE DOCKER DEVELOPMENT
# ==============================================

.PHONY: help up down build restart logs shell migrate seed fresh test clean status

# Variáveis
DOCKER_COMPOSE = docker compose
DOCKER_COMPOSE_FILE = docker-compose.yml
CONTAINER_APP = linkchartapi-dev

help: ## Mostrar ajuda
	@echo "📋 Comandos disponíveis:"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

up: ## Iniciar todos os containers
	@echo "🚀 Iniciando ambiente de desenvolvimento..."
	$(DOCKER_COMPOSE) -f $(DOCKER_COMPOSE_FILE) up -d
	@echo "✅ Ambiente iniciado!"
	@echo "🌐 Aplicação: http://localhost:8000"
	@echo "🏥 Health: http://localhost:8000/health"

down: ## Parar todos os containers
	@echo "🛑 Parando containers..."
	$(DOCKER_COMPOSE) -f $(DOCKER_COMPOSE_FILE) down
	@echo "✅ Containers parados!"

build: ## Fazer build dos containers
	@echo "🏗️ Fazendo build dos containers..."
	$(DOCKER_COMPOSE) -f $(DOCKER_COMPOSE_FILE) build --no-cache
	@echo "✅ Build concluído!"

restart: down up ## Reiniciar containers

logs: ## Mostrar logs da aplicação
	@echo "📋 Logs da aplicação:"
	$(DOCKER_COMPOSE) -f $(DOCKER_COMPOSE_FILE) logs -f $(CONTAINER_APP)

logs-all: ## Mostrar logs de todos os containers
	@echo "📋 Logs de todos os containers:"
	$(DOCKER_COMPOSE) -f $(DOCKER_COMPOSE_FILE) logs -f

shell: ## Abrir shell no container da aplicação
	@echo "🐚 Abrindo shell no container..."
	docker exec -it $(CONTAINER_APP) /bin/bash

shell-root: ## Abrir shell como root no container
	@echo "🐚 Abrindo shell como root..."
	docker exec -it -u root $(CONTAINER_APP) /bin/bash

migrate: ## Executar migrações
	@echo "🗄️ Executando migrações..."
	docker exec $(CONTAINER_APP) php /var/www/artisan migrate

migrate-fresh: ## Recriar banco e executar migrações
	@echo "🗄️ Recriando banco e executando migrações..."
	docker exec $(CONTAINER_APP) php /var/www/artisan migrate:fresh --seed

seed: ## Executar seeders
	@echo "🌱 Executando seeders..."
	docker exec $(CONTAINER_APP) php /var/www/artisan db:seed

fresh: migrate-fresh ## Recriar banco completamente

test: ## Executar testes
	@echo "🧪 Executando testes..."
	docker exec $(CONTAINER_APP) php /var/www/artisan test

cache-clear: ## Limpar todos os caches
	@echo "🧹 Limpando caches..."
	docker exec $(CONTAINER_APP) php /var/www/artisan config:clear
	docker exec $(CONTAINER_APP) php /var/www/artisan cache:clear
	docker exec $(CONTAINER_APP) php /var/www/artisan route:clear
	docker exec $(CONTAINER_APP) php /var/www/artisan view:clear

optimize: ## Otimizar aplicação
	@echo "⚡ Otimizando aplicação..."
	docker exec $(CONTAINER_APP) php /var/www/artisan config:cache
	docker exec $(CONTAINER_APP) php /var/www/artisan route:cache
	docker exec $(CONTAINER_APP) php /var/www/artisan view:cache

clean: ## Limpar containers e volumes não utilizados
	@echo "🧹 Limpando containers e volumes..."
	docker system prune -f
	docker volume prune -f
	@echo "✅ Limpeza concluída!"

status: ## Mostrar status dos containers
	@echo "📊 Status dos containers:"
	$(DOCKER_COMPOSE) -f $(DOCKER_COMPOSE_FILE) ps
	@echo ""
	@echo "🔍 Containers em execução:"
	@docker ps --filter "name=linkchartapi-dev\|linkchartdb-dev\|linkchartredis-dev"

install: ## Instalar dependências do Composer
	@echo "📦 Instalando dependências..."
	docker exec $(CONTAINER_APP) composer install --optimize-autoloader

composer-update: ## Atualizar dependências do Composer
	@echo "📦 Atualizando dependências..."
	docker exec $(CONTAINER_APP) composer update

key-generate: ## Gerar chave da aplicação
	@echo "🔑 Gerando chave da aplicação..."
	docker exec $(CONTAINER_APP) php /var/www/artisan key:generate

storage-link: ## Criar link simbólico para storage
	@echo "🔗 Criando link para storage..."
	docker exec $(CONTAINER_APP) php /var/www/artisan storage:link

setup: up migrate seed storage-link ## Setup completo do ambiente

health: ## Verificar saúde da aplicação
	@echo "🏥 Verificando saúde da aplicação..."
	@curl -f http://localhost:8000/health && echo "✅ Aplicação OK!" || echo "❌ Aplicação com problemas!"

# Comandos para produção
prod-up: ## Iniciar ambiente de produção
	@echo "🚀 Iniciando ambiente de produção..."
	$(DOCKER_COMPOSE) -f docker-compose.prod.yml up -d

prod-down: ## Parar ambiente de produção
	@echo "🛑 Parando ambiente de produção..."
	$(DOCKER_COMPOSE) -f docker-compose.prod.yml down

prod-build: ## Build do ambiente de produção
	@echo "🏗️ Build do ambiente de produção..."
	$(DOCKER_COMPOSE) -f docker-compose.prod.yml build --no-cache

prod-logs: ## Logs do ambiente de produção
	@echo "📋 Logs do ambiente de produção:"
	$(DOCKER_COMPOSE) -f docker-compose.prod.yml logs -f
