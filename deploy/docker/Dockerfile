# syntax=docker/dockerfile:1.7

# ==========================================
# MallBase 后端容器（PHP 8.2 + Swoole）
# ==========================================

FROM phpswoole/swoole:php8.2-alpine@sha256:625a200bce9198e8867616e5c0f976ba116976adb70b67c76403aa245baca8d4 AS sealed-context-validation

WORKDIR /sealed

ARG MALLBASE_EXPECTED_RECEIPT_ID
ARG MALLBASE_EXPECTED_SEAL_ID

# 这个阶段只校验 Agent 生成的合成证明。一次性 challenge 通过
# BuildKit secret 挂载，不会被 COPY 到任何镜像层。
COPY .mallbase-sealed-context.json /sealed/.mallbase-sealed-context.json
COPY .mallbase-deployment.json /sealed/.mallbase-deployment.json
COPY deploy/docker/validate-sealed-attestation.php /sealed/validate-sealed-attestation.php
RUN --mount=type=secret,id=mallbase_context_seal,required=true \
    php /sealed/validate-sealed-attestation.php verify-attestation \
        /sealed/.mallbase-sealed-context.json \
        /run/secrets/mallbase_context_seal \
        /sealed/.mallbase-deployment.json \
        "$MALLBASE_EXPECTED_RECEIPT_ID" \
        "$MALLBASE_EXPECTED_SEAL_ID" \
        /sealed/validated.ok

FROM phpswoole/swoole:php8.2-alpine@sha256:625a200bce9198e8867616e5c0f976ba116976adb70b67c76403aa245baca8d4 AS mallbase-runtime

WORKDIR /app

# 通过只读阶段挂载建立强制依赖，不把证明、challenge 或校验文件复制到最终层。
RUN --mount=type=bind,from=sealed-context-validation,source=/sealed/validated.ok,target=/run/mallbase-sealed-context.validated,ro \
    test -f /run/mallbase-sealed-context.validated

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
COPY .mallbase-deployment.json /.mallbase-deployment.json
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p runtime public/uploads .mallbase-env \
    && ln -s /app/.mallbase-env/backend.env /app/.env \
    && chown -R mallbase:mallbase runtime public/uploads .mallbase-env

COPY deploy/docker/docker-entrypoint.sh /usr/local/bin/
COPY deploy/docker/export-backend-env.php /usr/local/bin/
COPY deploy/docker/runtime-init.sh /usr/local/bin/
COPY deploy/docker/legacy-state-export-verify.sh /usr/local/bin/
COPY deploy/docker/legacy-state-import.sh /usr/local/bin/
COPY deploy/docker/target-state-verify.sh /usr/local/bin/
COPY deploy/docker/run-target-php.php /usr/local/bin/
COPY deploy/docker/validate-storage-cutover.php /usr/local/bin/
RUN chmod 0555 \
    /usr/local/bin/docker-entrypoint.sh \
    /usr/local/bin/export-backend-env.php \
    /usr/local/bin/runtime-init.sh \
    /usr/local/bin/legacy-state-export-verify.sh \
    /usr/local/bin/legacy-state-import.sh \
    /usr/local/bin/target-state-verify.sh \
    /usr/local/bin/run-target-php.php \
    /usr/local/bin/validate-storage-cutover.php

EXPOSE 8080

USER mallbase

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "think", "swoole"]
