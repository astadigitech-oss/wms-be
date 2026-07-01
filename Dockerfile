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
# 2) FRANKENPHP RUNTIME
##############################################
FROM dunglas/frankenphp:1.2.5-php8.3-bookworm

WORKDIR /app

ENV TZ=Asia/Jakarta \
    APP_ENV=production \
    APP_DEBUG=false

# Install PHP extensions
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

# Copy hanya yang dibutuhkan
COPY . .
COPY --from=composer_build /app/vendor ./vendor

# Generate key (hanya jika belum ada)
RUN if [ ! -f .env ]; then cp .env.example .env; fi \
 && php artisan key:generate --force

# Cache untuk performa
RUN php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache

# Storage link (tidak error kalau sudah ada)
RUN php artisan storage:link || true

EXPOSE 8000

CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=auto"]
