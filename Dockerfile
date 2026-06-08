# syntax=docker/dockerfile:1

##
# base — PHP runtime + extensions + Composer. No application code lives here,
# so the dev setup can bind-mount the project and never rebuild for code or
# dependency changes.
##
FROM php:8.3-fpm-bookworm AS base

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libpq-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        bcmath \
        pcntl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Match host UID/GID to avoid permission issues on bind mounts
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data

WORKDIR /var/www/html

##
# dev — code and vendor/ arrive via the bind mount, not the image. Build this
# once; edit code freely without rebuilding. Run `composer install` inside the
# container (or on the host) when dependencies change.
##
FROM base AS dev

USER www-data

EXPOSE 9000
CMD ["php-fpm"]

##
# production — self-contained image with code + vendor baked in (no bind mount).
# Build with: docker build --target production -t notifications-app:prod .
##
FROM base AS production

# Install PHP dependencies first (better layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

# Copy the rest of the application
COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
