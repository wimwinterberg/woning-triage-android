# Local / Compose image for the Symfony API. Production on App Platform uses the PHP buildpack.
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-install intl pdo_pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
COPY bin bin
COPY config config
COPY fixtures fixtures
COPY migrations migrations
COPY public public
COPY src src
COPY .env .env.example ./

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && mkdir -p var

COPY docker/entrypoint.sh /usr/local/bin/woningtriage-entrypoint
RUN chmod +x /usr/local/bin/woningtriage-entrypoint

EXPOSE 8000
ENTRYPOINT ["woningtriage-entrypoint"]
CMD ["php", "-d", "display_errors=0", "-S", "0.0.0.0:8000", "-t", "public"]
