FROM php:8.4-fpm-alpine

# System packages required by the PHP extensions below.
# - icu-dev        : intl (currency/number formatting for reports)
# - oniguruma-dev  : mbstring
# - libzip-dev     : zip (XLSX export)
# - freetype/jpeg/png : gd (receipt image preprocessing before OCR)
# - poppler-utils  : pdftoppm, rasterises PDF receipts before OCR. The OCR
#                    model accepts images only, so without this every PDF
#                    receipt would silently fail.
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
RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app
USER app
