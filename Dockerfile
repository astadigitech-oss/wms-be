##############################################
# 1) COMPOSER BUILD STAGE (PHP 8.1)
##############################################
FROM php:8.1-cli AS composer_build

RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpng-dev libjpeg-dev libfreetype-dev libzip-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install intl gd zip pcntl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# install backend deps
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader


##############################################
# 2) FRANKENPHP RUNTIME STAGE
##############################################
FROM dunglas/frankenphp:1-php8.1 AS runtime

WORKDIR /app

ENV TZ=Asia/Jakarta

# install php extensions untuk octane
RUN install-php-extensions \
    pdo_mysql \
    intl \
    gd \
    mbstring \
    bcmath \
    opcache \
    zip \
    sockets \
    pcntl \
    posix \
    redis

# copy app & built assets
COPY . .
COPY --from=composer_build /app/vendor ./vendor
COPY --from=node_build /app/public/build ./public/build

RUN php artisan storage:link || true

EXPOSE 8000

# CMD ["frankenphp", "php-server"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=2"]
