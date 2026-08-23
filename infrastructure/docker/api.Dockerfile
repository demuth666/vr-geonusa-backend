FROM composer:2.10.2 AS composer

FROM php:8.4-cli-alpine

RUN apk add --no-cache libpq libxml2 oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev libxml2-dev oniguruma-dev \
    && docker-php-ext-install -j$(nproc) dom mbstring pdo_pgsql \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts

COPY apps/api/ ./
RUN cp .env.example .env \
    && composer dump-autoload --optimize \
    && chown -R www-data:www-data .env bootstrap/cache storage

USER www-data

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
