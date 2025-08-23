#!/bin/sh

# ==========================================
# SCRIPT DE CORREÇÃO DE PERMISSÕES
# ==========================================

echo "🔧 Corrigindo permissões para Laravel..."

# Navegar para o diretório da aplicação
cd /var/www

# Criar diretórios se não existirem
echo "📁 Criando diretórios necessários..."
mkdir -p storage/logs
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing
mkdir -p storage/app/public
mkdir -p bootstrap/cache

# Criar arquivos de log se não existirem
echo "📋 Criando arquivos de log..."
touch storage/logs/laravel.log
touch storage/logs/laravel-$(date +%Y-%m-%d).log

# Configurar ownership correto
echo "👤 Configurando ownership..."
chown -R www-data:www-data /var/www
chown -R www-data:www-data storage/
chown -R www-data:www-data bootstrap/cache/

# Configurar permissões específicas
echo "🔐 Configurando permissões..."

# Diretórios principais
chmod -R 755 /var/www
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/

# Permissões específicas para logs
chmod -R 777 storage/logs/
chmod 666 storage/logs/*.log

# Permissões específicas para cache
chmod -R 777 storage/framework/cache/
chmod -R 777 storage/framework/sessions/
chmod -R 777 storage/framework/views/
chmod -R 777 storage/framework/testing/

# Permissões para storage/app
chmod -R 775 storage/app/

echo "✅ Permissões corrigidas!"

# Verificar se os diretórios estão writable
echo "🔍 Verificando permissões..."

if [ -w storage/logs/ ]; then
    echo "✅ storage/logs/ writable"
else
    echo "❌ storage/logs/ NOT writable"
    chmod 777 storage/logs/
fi

if [ -w storage/framework/cache/data/ ]; then
    echo "✅ storage/framework/cache/data/ writable"
else
    echo "❌ storage/framework/cache/data/ NOT writable"
    chmod 777 storage/framework/cache/data/
fi

if [ -w bootstrap/cache/ ]; then
    echo "✅ bootstrap/cache/ writable"
else
    echo "❌ bootstrap/cache/ NOT writable"
    chmod 777 bootstrap/cache/
fi

echo "🎉 Correção de permissões concluída!"
