ARG PHP_IMAGE
FROM composer@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM ${PHP_IMAGE}

COPY .central-apt-source/docker/apt/require-fixed-bookworm-apt.sh /usr/local/share/durable-workflow/php-ci/require-fixed-bookworm-apt.sh
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php-ci/verify-php-trust.php /usr/local/share/durable-workflow/php-ci/verify-php-trust.php
COPY docker/brotli /usr/local/share/durable-workflow/php-ci/brotli

RUN /bin/sh /usr/local/share/durable-workflow/php-ci/require-fixed-bookworm-apt.sh \
    && printf 'Acquire::Retries "0";\n' > /etc/apt/apt.conf.d/99-no-retries \
    && php /usr/local/share/durable-workflow/php-ci/verify-php-trust.php /usr/local/bin/composer \
    && sh /usr/local/share/durable-workflow/php-ci/brotli/install-vendor.sh /usr/local/share/durable-workflow/php-ci/brotli \
        git unzip python3 gcc libc6-dev libicu-dev libonig-dev libzip-dev \
    && docker-php-ext-install -j2 bcmath intl mbstring pcntl sockets zip \
    && cc -O2 /usr/local/share/durable-workflow/php-ci/brotli/verify-system-brotli.c -lbrotlidec -lbrotlienc -ldl \
        -o /usr/local/share/durable-workflow/php-ci/brotli/verify-system-brotli \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && php /usr/local/share/durable-workflow/php-ci/verify-php-trust.php /usr/local/bin/composer \
    && php /usr/local/share/durable-workflow/php-ci/brotli/verify-runtime.php \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
