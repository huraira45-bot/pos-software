FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        supervisor \
        unzip \
        git \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        bcmath \
        zip \
        intl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache-custom.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-custom.ini
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN mkdir -p /var/log/supervisor

CMD ["php-fpm"]
