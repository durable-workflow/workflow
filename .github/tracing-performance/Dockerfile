FROM php:8.3.35-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
        git libonig-dev libxml2-dev unzip \
    && docker-php-ext-install pdo_mysql mbstring dom pcntl \
    && pecl install redis-6.1.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
USER 1000:1000
WORKDIR /app
