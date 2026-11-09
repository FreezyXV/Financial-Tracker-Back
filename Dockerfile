# Image de production Railway : PHP 8.3 + PostgreSQL, serveur sur $PORT (8080 par défaut).
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev \
 && docker-php-ext-install pdo_pgsql zip intl opcache \
 && rm -rf /var/lib/apt/lists/*

# OPcache pour le serveur CLI : le code compilé reste en mémoire entre les requêtes.
RUN { echo 'opcache.enable=1'; echo 'opcache.enable_cli=1'; echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=128'; echo 'opcache.max_accelerated_files=20000'; echo 'realpath_cache_size=4096K'; } \
    > /usr/local/etc/php/conf.d/zz-performance.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

ENV APP_ENV=production \
    LOG_CHANNEL=stderr \
    PHP_CLI_SERVER_WORKERS=4 \
    PORT=8080

EXPOSE 8080

CMD ["sh", "docker-start.sh"]
