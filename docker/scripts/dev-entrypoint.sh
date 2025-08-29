#!/bin/bash

echo "🚀 Iniciando ambiente de desenvolvimento Laravel..."

# Aguardar serviços dependentes
echo "⏳ Aguardando PostgreSQL..."
while ! nc -z database 5432; do
    sleep 1
done
echo "✅ PostgreSQL conectado!"

echo "⏳ Aguardando Redis..."
while ! nc -z redis 6379; do
    sleep 1
done
echo "✅ Redis conectado!"

# Configurar diretórios e permissões
echo "🔧 Configurando diretórios e permissões..."
mkdir -p /var/www/storage/framework/cache/data
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/framework/testing
mkdir -p /var/www/storage/logs
mkdir -p /var/www/storage/app/public
mkdir -p /var/www/bootstrap/cache

# Forçar criação e permissões do arquivo de log
touch /var/www/storage/logs/laravel.log
chown -R www-data:www-data /var/www/storage
chown -R www-data:www-data /var/www/bootstrap/cache
chmod -R 777 /var/www/storage
chmod -R 777 /var/www/bootstrap/cache
chmod 666 /var/www/storage/logs/laravel.log

echo "✅ Permissões configuradas:"
ls -la /var/www/storage/logs/

# Instalar dependências se necessário
if [ ! -d "/var/www/vendor" ] || [ ! -f "/var/www/vendor/autoload.php" ]; then
    echo "📦 Instalando dependências do Composer..."
    composer install --optimize-autoloader
fi

# Gerar chave da aplicação se necessário
if ! grep -q "APP_KEY=base64:" /var/www/.env; then
    echo "🔑 Gerando chave da aplicação..."
    php artisan key:generate
fi

# Executar migrações
echo "🗄️ Executando migrações..."
php artisan migrate --force

# Verificar se há dados na tabela de links
LINK_COUNT=$(php artisan tinker --execute="echo App\Models\Link::count();" 2>/dev/null | tail -1 || echo "0")
if [ "$LINK_COUNT" -eq 0 ]; then
    echo "🌱 Executando seeders..."
    php artisan db:seed --force
fi

# Criar link simbólico para storage
if [ ! -L /var/www/public/storage ]; then
    echo "🔗 Criando link simbólico para storage..."
    php artisan storage:link
fi

# Corrigir nginx para CORS (remover bloqueio de OPTIONS)
echo "🔧 Configurando nginx para CORS..."
sed -i '/if ($request_method = '\''OPTIONS'\'')/,/}/d' /etc/nginx/conf.d/default.conf
nginx -s reload 2>/dev/null || true

# Limpar caches
echo "🧹 Limpando caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "✅ Ambiente de desenvolvimento configurado!"
echo "🌐 Aplicação disponível em: http://localhost:8000"
echo "🏥 Health check: http://localhost:8000/health"

# Iniciar supervisor
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
