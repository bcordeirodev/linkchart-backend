# ==========================================
# DOCKERFILE PARA PRODUÇÃO - DIGITALOCEAN
# ==========================================

FROM php:8.2-fpm-alpine

# Instalar dependências do sistema
RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    redis \
    git \
    unzip \
    curl \
    oniguruma-dev \
    libxml2-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev

# Instalar dependências de build temporárias para Redis
RUN apk add --no-cache --virtual .build-deps \
    autoconf \
    gcc \
    g++ \
    make \
    pkgconfig

# Instalar extensões PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo_pgsql \
    mbstring \
    xml \
    gd \
    zip \
    bcmath \
    opcache \
    pcntl

# Instalar Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# OpenTelemetry auto-instrumentation extension (Zend hooks), pinned for reproducible builds
RUN pecl install opentelemetry-1.2.1 && docker-php-ext-enable opentelemetry

# Excimer — low-overhead sampling profiler (Wikimedia). Feeds continuous
# profiling to Grafana Pyroscope via App\Profiling\PyroscopeProfiler. Enabled
# only when PYROSCOPE_ENABLED=true (kill switch); the profiler samples a small
# fraction of requests and pushes after the response, so it's inert otherwise.
RUN pecl install excimer && docker-php-ext-enable excimer

# Remover dependências de build temporárias
RUN apk del .build-deps

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar PHP para produção
COPY docker/php/php-prod.ini /usr/local/etc/php/php.ini
COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/opcache.ini

# Configurar Nginx para produção
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf

# Supervisor: dois perfis na mesma imagem.
#   web    (CMD default)  -> php-fpm + nginx        -> docker-compose.app.yml
#   worker (command:)     -> filas + scheduler      -> docker-compose.worker.yml
# O servico `worker` sobrescreve o `command:` no compose. Nao ha entrypoint.
#
# Separados porque o deploy da web e blue/green: se as filas morassem no mesmo
# container, o overlap entre as duas cores rodaria DOIS schedulers.
COPY docker/supervisor/supervisord-web.conf /etc/supervisor/conf.d/supervisord-web.conf
COPY docker/supervisor/supervisord-worker.conf /etc/supervisor/conf.d/supervisord-worker.conf

# PHP-FPM precisa rodar como `www` (mesmo user dos workers do supervisord
# e do owner de /var/www/storage). A imagem base usa `www-data` por padrão
# e isso causa Permission denied quando o queue worker (www) cria o
# laravel-YYYY-MM-DD.log e o PHP-FPM (www-data) tenta dar append.
RUN sed -i \
    -e 's/^user = www-data/user = www/' \
    -e 's/^group = www-data/group = www/' \
    -e 's/^listen.owner = www-data/listen.owner = www/' \
    -e 's/^listen.group = www-data/listen.group = www/' \
    /usr/local/etc/php-fpm.d/www.conf

# Criar usuário para aplicação e diretórios necessários
RUN addgroup -g 1000 www && \
    adduser -D -s /bin/sh -u 1000 -G www www && \
    mkdir -p /var/log/supervisor /var/log/nginx && \
    chown -R www:www /var/log/supervisor

# Definir diretório de trabalho
WORKDIR /var/www

# Copiar aplicação
COPY --chown=www:www . /var/www

# Configurar Git para permitir diretório e instalar dependências
# --no-scripts pula post-autoload-dump (package:discover); rodamos explicitamente
# depois para garantir que packages.php reflita apenas pacotes non-dev instalados.
RUN git config --global --add safe.directory /var/www \
    && git config --global --add safe.directory /var/www/vendor/fakerphp/faker \
    && composer install --optimize-autoloader --no-dev --no-scripts \
    && rm -f /var/www/bootstrap/cache/packages.php /var/www/bootstrap/cache/services.php \
    && php /var/www/artisan package:discover --ansi

# Criar diretórios necessários e configurar permissões
RUN mkdir -p /var/www/storage/framework/cache/data \
    && mkdir -p /var/www/storage/framework/sessions \
    && mkdir -p /var/www/storage/framework/views \
    && mkdir -p /var/www/storage/framework/testing \
    && mkdir -p /var/www/storage/logs \
    && mkdir -p /var/www/bootstrap/cache \
    && touch /var/www/storage/logs/laravel.log \
    && chown -R www:www /var/www \
    && chmod -R 755 /var/www \
    && chmod -R 775 /var/www/storage \
    && chmod -R 775 /var/www/bootstrap/cache \
    && chmod 664 /var/www/storage/logs/laravel.log

# Expor porta
EXPOSE 80

# Comando de inicialização — perfil WEB por padrão.
# O container `worker` sobrescreve com supervisord-worker.conf via `command:`.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord-web.conf"]
