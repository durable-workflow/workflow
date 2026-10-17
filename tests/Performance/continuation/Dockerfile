FROM composer@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d

RUN docker-php-ext-install -j1 pcntl \
    && php -r 'exit(function_exists("pcntl_async_signals") && function_exists("pcntl_signal") ? 0 : 1);'

WORKDIR /app

USER 1000:1000
