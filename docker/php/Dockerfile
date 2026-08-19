FROM php:8.4-fpm-alpine

# System packages required by the PHP extensions below.
# - icu-dev        : intl (currency/number formatting for reports)
# - oniguruma-dev  : mbstring
# - libzip-dev     : zip (XLSX export)
# - freetype/jpeg/png : gd (receipt image preprocessing before OCR)
# - poppler-utils  : pdftoppm, rasterises PDF receipts before OCR. The OCR
#                    model accepts images only, so without this every PDF
#                    receipt would silently fail.
#
# No MySQL client is installed, deliberately. Alpine ships MariaDB's client,
# which cannot authenticate against MySQL 8 (no caching_sha2_password plugin) --
# every dump it produced was empty. backup:run therefore dumps through PDO, and
# the documented restore runs the client inside the mysql container instead
# (docs/14-deployment.md). Leaving a broken client installed would suggest it
# works.
RUN apk add --no-cache \
        bash \
        git \
        poppler-utils \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# Run as a non-root user so files written into the bind mount stay editable
# on the host and the container has no unnecessary privileges.
#
# The uid/gid are build arguments rather than hardcoded: on a Linux host the
# container user must match the host user that owns the bind mount, and that is
# not always 1000. Build with:
#   docker compose build --build-arg APP_UID=$(id -u) --build-arg APP_GID=$(id -g)
ARG APP_UID=1000
ARG APP_GID=1000

RUN addgroup -g ${APP_GID} app \
    && adduser -u ${APP_UID} -G app -s /bin/sh -D app
USER app
