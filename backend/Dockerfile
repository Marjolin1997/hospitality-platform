FROM php:8.4-fpm-alpine
RUN apk add --no-cache git unzip icu-dev libzip-dev oniguruma-dev libxml2-dev curl-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql intl zip bcmath dom curl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY . .
RUN composer dump-autoload --optimize
CMD ["php-fpm"]
