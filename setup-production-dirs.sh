#!/bin/sh

# ===========================================
# SETUP RÁPIDO DE DIRETÓRIOS PARA PRODUÇÃO
# ===========================================

echo "🔧 Configurando diretórios essenciais para produção..."

# Criar diretórios do framework Laravel
mkdir -p /var/www/storage/framework/cache/data
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/framework/testing
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache

# Criar arquivo de log se não existir
touch /var/www/storage/logs/laravel.log

# Configurar permissões
chown -R www:www /var/www/storage
chown -R www:www /var/www/bootstrap/cache
chmod -R 775 /var/www/storage
chmod -R 775 /var/www/bootstrap/cache
chmod 664 /var/www/storage/logs/laravel.log

echo "✅ Diretórios configurados com sucesso!"
