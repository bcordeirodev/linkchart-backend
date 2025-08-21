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

# Remover dependências de build temporárias
RUN apk del .build-deps

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar PHP para produção
COPY docker/php/php.ini /usr/local/etc/php/php.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Configurar Nginx
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Configurar Supervisor
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

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
RUN git config --global --add safe.directory /var/www \
    && git config --global --add safe.directory /var/www/vendor/fakerphp/faker \
    && composer install --optimize-autoloader --no-dev --no-scripts

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

# Comando de inicialização
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
