# syntax=docker/dockerfile:1.7

# ==========================================
# MallBase 后端容器（PHP 8.2 + Swoole）
# ==========================================

FROM phpswoole/swoole:php8.2-alpine@sha256:625a200bce9198e8867616e5c0f976ba116976adb70b67c76403aa245baca8d4 AS mallbase-runtime

WORKDIR /app

ARG MALLBASE_APP_UID=10000
ARG MALLBASE_APP_GID=10000

# PHP 扩展
RUN apk add --no-cache \
    mariadb-client \
    util-linux \
    libcap \
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
        pcntl \
        posix \
        opcache \
    && rm -rf /var/cache/apk/*

# 生产业务进程使用固定、无特权身份。宿主机 Agent 使用独立 UID，
# 共享目录通过 Compose 追加宿主机共享 GID，不让 PHP 以 root 运行。
RUN addgroup -g "$MALLBASE_APP_GID" -S mallbase \
    && adduser -u "$MALLBASE_APP_UID" -S -D -H -G mallbase mallbase

# Swoole registers opcode handlers that make PHP JIT disable itself with a
# startup warning. Keep CLI OPCache enabled, but disable the incompatible JIT
# explicitly so helper stdout remains machine-readable.
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size=0" >> /usr/local/etc/php/conf.d/opcache.ini

# Composer
COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer

# 依赖安装（利用 Docker 缓存层）
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# 应用代码
COPY backend/ .
COPY .version /.version
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p runtime public/uploads .mallbase-env storage/cert public/static/demo public/storage \
    && ln -s /app/.mallbase-env/backend.env /app/.env \
    && chown -R mallbase:mallbase \
        runtime public/uploads .mallbase-env storage/cert public/static/demo public/storage

COPY deploy/docker/docker-entrypoint.sh /usr/local/bin/
COPY deploy/docker/export-backend-env.php /usr/local/bin/
RUN chmod 0555 \
    /usr/local/bin/docker-entrypoint.sh \
    /usr/local/bin/export-backend-env.php
COPY --chmod=0444 LICENSE /LICENSE

EXPOSE 8080

USER mallbase

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "think", "swoole"]
