FROM php:8.4-cli-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev \
    && docker-php-ext-install bcmath mbstring pcntl pdo_mysql zip \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --prefer-dist --no-interaction --no-scripts
COPY . .
COPY ops/php.ini /usr/local/etc/php/conf.d/payflow.ini
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize \
    && touch .env && mkdir -p storage/app/private && chown -R www-data:www-data storage bootstrap/cache
USER www-data
