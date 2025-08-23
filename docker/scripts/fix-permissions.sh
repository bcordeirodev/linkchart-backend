#!/bin/sh

# ==========================================
# SCRIPT DE CORREÇÃO DE PERMISSÕES
# ==========================================
#
# CORREÇÃO CRÍTICA (2025-08-23):
# - Problema identificado: Laravel cria subdiretórios em cache/data/
#   com ownership root:root em vez de www-data:www-data
# - Solução: Aplicar chown após chmod para garantir ownership correto
# - Adicionado teste de criação de subdiretórios para validação
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
chmod 666 storage/logs/*.log 2>/dev/null || true

# Permissões específicas para cache (CRÍTICO: 777 + ownership correto)
chmod -R 777 storage/framework/cache/
chmod -R 777 storage/framework/sessions/
chmod -R 777 storage/framework/views/
chmod -R 777 storage/framework/testing/

# Permissões para storage/app
chmod -R 775 storage/app/

# CORREÇÃO CRÍTICA: Garantir ownership correto após permissões
echo "🔧 Aplicando correção crítica de ownership..."
chown -R www-data:www-data storage/framework/cache/
chown -R www-data:www-data storage/framework/sessions/
chown -R www-data:www-data storage/framework/views/
chown -R www-data:www-data storage/framework/testing/

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

# VERIFICAÇÃO ADICIONAL: Testar criação de subdiretórios no cache
echo "🧪 Testando criação de subdiretórios de cache..."
TEST_DIR="storage/framework/cache/data/test/$(date +%s)"
if mkdir -p "$TEST_DIR" 2>/dev/null; then
    echo "✅ Subdiretórios de cache podem ser criados"
    rm -rf "storage/framework/cache/data/test" 2>/dev/null || true
else
    echo "❌ ERRO: Não é possível criar subdiretórios de cache"
    echo "🔧 Aplicando correção emergencial..."
    chmod -R 777 storage/framework/cache/
    chown -R www-data:www-data storage/framework/cache/
fi

echo "🎉 Correção de permissões concluída!"
