##############################################
# 1) COMPOSER BUILD STAGE
##############################################
FROM php:8.3-cli AS composer_build

RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpng-dev libjpeg-dev libfreetype-dev libzip-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install intl gd zip pcntl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader


##############################################
# 2) RUNTIME (NO frankenphp image!)
##############################################
FROM php:8.3-cli

WORKDIR /app

ENV TZ=Asia/Jakarta \
    APP_ENV=production \
    APP_DEBUG=false

RUN apt-get update && apt-get install -y \
    git unzip curl libicu-dev libpng-dev libjpeg-dev libfreetype-dev libzip-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install \
    pdo_mysql intl gd zip pcntl bcmath opcache

# Copy app
COPY . .
COPY --from=composer_build /app/vendor ./vendor

# Setup env & key
RUN if [ ! -f .env ]; then cp .env.example .env; fi \
 && php artisan key:generate --force

# Install Octane + FrankenPHP binary (INILAH KUNCINYA)
RUN php artisan octane:install --server=frankenphp --no-interaction

# Cache
RUN php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache

RUN php artisan storage:link || true

EXPOSE 8000

CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=6", "--max-requests=1000"]
