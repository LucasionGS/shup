# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: PHP base
# ---------------------------------------------------------------------------
# Every PHP stage descends from this one, so the vendor, dev and runtime images
# are guaranteed to agree on the PHP version and the extension set. Resolving
# the lock file against a different PHP major than the one that actually runs
# the app fails on platform requirements, and that is easy to do by accident
# when each stage names its own base image.
FROM php:8.4-fpm-alpine AS base

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

# ---------------------------------------------------------------------------
# Stage 2: front-end assets
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
# Stage 3: PHP dependencies
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache unzip

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
# Stage 4: developer toolchain
# ---------------------------------------------------------------------------
# Carries no application code on purpose: the project is bind-mounted at run
# time instead. .dockerignore excludes tests/ and vendor/ from the build
# context, so a code-carrying image could never run the test suite, and a
# bind mount also lets composer write composer.lock straight back to the host.
#
#   docker build --target dev -t shup-dev .
#   docker run --rm -it -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" \
#       shup-dev composer update
#   docker run --rm -it -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" \
#       shup-dev php artisan test
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache unzip

# Writable by any uid, so --user matching the host account still has a home for
# composer's cache and config.
ENV COMPOSER_HOME=/tmp/composer
RUN mkdir -p /tmp/composer && chmod 777 /tmp/composer

WORKDIR /app

# ---------------------------------------------------------------------------
# Stage 5: runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

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
