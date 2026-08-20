# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: front-end assets
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json* bun.lockb* ./
# The lockfile in this repo is bun's, but npm resolves the same manifest and is
# available in the node image without extra tooling.
RUN npm install --no-audit --no-fund

COPY vite.config.ts tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2: PHP dependencies
# ---------------------------------------------------------------------------
# Built on the same PHP version as the runtime stage: the composer image tracks
# the latest PHP, and resolving the lock file against a newer major than the one
# that actually runs the app fails on platform requirements.
FROM php:8.3-cli-alpine AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache git unzip libzip-dev \
    && docker-php-ext-install zip

WORKDIR /app

COPY composer.json composer.lock ./

# artisan is not present yet, so package discovery has to wait for the app code.
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 3: runtime
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# gd backs thumbnail generation; pdo_mysql is the MariaDB driver; redis backs
# cache, sessions and the queue; zip and bcmath are used by the framework.
RUN apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        libzip \
        icu-libs \
        git \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo_mysql \
        zip \
        bcmath \
        intl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-shup.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-shup.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Package discovery needs both the app code and the vendor directory.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
