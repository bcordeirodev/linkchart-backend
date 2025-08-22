#!/bin/bash

# ==========================================
# SCRIPT DE SETUP DE DIRETÓRIOS PRODUÇÃO
# ==========================================

echo "🔧 Configurando diretórios essenciais para produção..."

# Criar diretórios necessários
mkdir -p /var/www/storage/framework/cache/data
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/framework/testing
mkdir -p /var/www/storage/logs
mkdir -p /var/www/storage/app/public
mkdir -p /var/www/bootstrap/cache

# Criar arquivo de log se não existir
touch /var/www/storage/logs/laravel.log

# Configurar permissões corretas
echo "🔐 Configurando permissões..."
chown -R www:www /var/www/storage
chown -R www:www /var/www/bootstrap/cache
chmod -R 775 /var/www/storage
chmod -R 775 /var/www/bootstrap/cache
chmod 664 /var/www/storage/logs/laravel.log

# Executar migrações se necessário
echo "🗄️ Executando migrações do banco de dados..."
php /var/www/artisan migrate --force

# Executar seeders se necessário (apenas se tabela de links estiver vazia)
LINK_COUNT=$(php /var/www/artisan tinker --execute="echo App\Models\Link::count();" 2>/dev/null | tail -1)
if [ "$LINK_COUNT" -eq 0 ]; then
    echo "🌱 Executando seeders iniciais..."
    php /var/www/artisan db:seed --force
fi

# Criar link simbólico para storage se não existir
if [ ! -L /var/www/public/storage ]; then
    echo "🔗 Criando link simbólico para storage..."
    php /var/www/artisan storage:link
fi

echo "✅ Setup de diretórios concluído!"
