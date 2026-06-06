# ==========================================
# MallBase 后端容器（PHP 8.2 + Swoole）
# ==========================================

FROM phpswoole/swoole:php8.2-alpine

WORKDIR /app

# PHP 扩展
RUN apk add --no-cache \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        mbstring \
        bcmath \
        opcache \
    && rm -rf /var/cache/apk/*

# OPCache + JIT
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size=128M" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 依赖安装（利用 Docker 缓存层）
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# 应用代码
COPY backend/ .
COPY .version /.version
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p runtime public/uploads \
    && chmod -R 777 runtime

COPY deploy/docker/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "think", "swoole"]
