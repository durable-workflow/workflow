FROM composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM php@sha256:61bc522867df9f96b932ddfef0be67a39cd488e8235ad68fea8b69d22e4b4135
LABEL org.opencontainers.image.source="https://github.com/durable-workflow/workflow"
RUN docker-php-ext-install -j1 bcmath opcache pcntl \
    && groupmod --gid 1000 www-data \
    && usermod --uid 1000 --gid 1000 www-data \
    && mkdir -p /app \
    && chown 1000:1000 /app
RUN docker-php-ext-install -j1 sockets
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY opcache.ini /usr/local/etc/php/conf.d/99-qualification.ini
WORKDIR /app
USER 1000:1000

