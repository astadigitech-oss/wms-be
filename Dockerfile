##############################################
# 1) COMPOSER BUILD STAGE
##############################################
FROM php:8.3-cli AS builder

RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpng-dev libjpeg-dev libfreetype-dev libzip-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install intl gd zip pcntl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# baru copy source
COPY . .

##############################################
# 2) RUNTIME (FRANKENPHP)
##############################################
FROM dunglas/frankenphp:1-php8.3

WORKDIR /app

RUN install-php-extensions \
    pdo_mysql intl gd mbstring bcmath opcache zip sockets pcntl posix redis

# copy hasil build saja
COPY --from=builder /app /app

# permission
RUN chown -R www-data:www-data /app \
    && chmod -R 775 /app/storage /app/bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=auto"]
