FROM php:8.4-fpm-alpine
RUN apk add --no-cache git unzip icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql intl zip bcmath
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY . .
RUN composer dump-autoload --optimize
CMD ["php-fpm"]
