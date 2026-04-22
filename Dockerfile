FROM dunglas/frankenphp:1-php8.3

WORKDIR /app

RUN install-php-extensions pdo_mysql mbstring bcmath opcache

COPY . .

RUN composer install --no-dev --optimize-autoloader

EXPOSE 8000

CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]
