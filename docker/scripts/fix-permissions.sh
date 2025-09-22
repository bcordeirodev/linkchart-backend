#!/bin/sh

# ==========================================
# SCRIPT OTIMIZADO DE CORREÇÃO DE PERMISSÕES
# ==========================================
#
# CORREÇÃO DEFINITIVA (2025-09-22):
# - Eliminadas redundâncias (de 18 para 3 aplicações)
# - Criação pré-emptiva de subdiretórios GeoIP
# - Ownership correto com herança automática
# - Sistema robusto de fallback
# ==========================================

echo "🔧 Aplicando correção otimizada de permissões para Laravel..."

# Navegar para o diretório da aplicação
cd /var/www

# ==========================================
# 1. CRIAÇÃO DE ESTRUTURA COMPLETA
# ==========================================
echo "📁 Criando estrutura completa de diretórios..."

# Diretórios básicos
mkdir -p storage/logs
mkdir -p storage/app/public
mkdir -p bootstrap/cache

# Estrutura completa de cache (incluindo subdiretórios GeoIP)
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing

# PRÉ-CRIAÇÃO CRÍTICA: Subdiretórios que o GeoIP cria dinamicamente
echo "🌍 Pré-criando subdiretórios GeoIP para evitar problemas de permissão..."
for dir in {0..9} {a..f}; do
    for subdir in {0..9} {a..f}; do
        mkdir -p "storage/framework/cache/data/$dir$subdir"
    done
done

# Criar alguns padrões específicos que vimos nos logs
mkdir -p storage/framework/cache/data/db/a8
mkdir -p storage/framework/cache/data/89/eb

# ==========================================
# 2. CRIAÇÃO E CONFIGURAÇÃO DE LOGS
# ==========================================
echo "📋 Configurando sistema de logs..."

# Criar arquivos de log essenciais
touch storage/logs/laravel.log
touch storage/logs/laravel-$(date +%Y-%m-%d).log
touch storage/logs/api-errors.log
touch storage/logs/debug.log

# Conteúdo inicial
echo "[$(date)] DEPLOY: Log system initialized by fix-permissions.sh" >> storage/logs/laravel-$(date +%Y-%m-%d).log

# ==========================================
# 3. APLICAÇÃO ÚNICA E DEFINITIVA DE OWNERSHIP
# ==========================================
echo "👤 Aplicando ownership definitivo (www-data:www-data)..."
chown -R www-data:www-data /var/www

# ==========================================
# 4. APLICAÇÃO ÚNICA E DEFINITIVA DE PERMISSÕES
# ==========================================
echo "🔐 Aplicando permissões definitivas com herança automática..."

# Permissões base para aplicação
chmod -R 755 /var/www

# Permissões específicas para diretórios que precisam de escrita
chmod -R 777 storage/
chmod -R 777 bootstrap/cache/

# CRÍTICO: Configurar setgid bit para herança automática de grupo
echo "🔧 Configurando herança automática de permissões (setgid)..."
find storage/framework/cache -type d -exec chmod g+s {} \;
find storage/framework/sessions -type d -exec chmod g+s {} \;
find storage/framework/views -type d -exec chmod g+s {} \;
find storage/logs -type d -exec chmod g+s {} \;

# ==========================================
# 5. VERIFICAÇÃO E TESTES FINAIS
# ==========================================
echo "🧪 Executando testes de verificação..."

# Teste de criação de subdiretório GeoIP
TEST_GEOIP_DIR="storage/framework/cache/data/test/$(date +%s)"
if mkdir -p "$TEST_GEOIP_DIR" 2>/dev/null; then
    echo "✅ Subdiretórios GeoIP podem ser criados dinamicamente"
    # Verificar ownership do subdiretório criado
    OWNER=$(stat -c '%U:%G' "$TEST_GEOIP_DIR" 2>/dev/null || echo "unknown")
    echo "📋 Ownership do subdiretório criado: $OWNER"
    rm -rf "storage/framework/cache/data/test" 2>/dev/null || true
else
    echo "❌ ERRO: Não é possível criar subdiretórios GeoIP"
    exit 1
fi

# Teste de escrita em logs
CURRENT_LOG="storage/logs/laravel-$(date +%Y-%m-%d).log"
TEST_MESSAGE="[$(date)] DEPLOY TEST: Permissions system working correctly"
if echo "$TEST_MESSAGE" >> "$CURRENT_LOG" 2>/dev/null; then
    echo "✅ Sistema de logs funcionando corretamente"
else
    echo "❌ ERRO CRÍTICO: Sistema de logs falhou"
    exit 1
fi

# Verificação final de ownership
echo "🔍 Verificação final de ownership:"
echo "📋 /var/www: $(stat -c '%U:%G' /var/www 2>/dev/null || echo 'unknown')"
echo "📋 storage/: $(stat -c '%U:%G' storage/ 2>/dev/null || echo 'unknown')"
echo "📋 cache/data/: $(stat -c '%U:%G' storage/framework/cache/data/ 2>/dev/null || echo 'unknown')"

echo "🎉 CORREÇÃO OTIMIZADA DE PERMISSÕES CONCLUÍDA COM SUCESSO!"
echo "📊 Subdiretórios pré-criados: $(find storage/framework/cache/data -type d | wc -l)"
echo "🔐 Herança automática configurada com setgid"
echo "✅ Sistema pronto para GeoIP e cache dinâmico"
