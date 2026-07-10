FROM php:8.2-cli

# Install system packages and PHP extensions required by Laravel
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring bcmath exif pcntl zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for better Docker layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

# Copy application source
COPY . .

# Run autoload optimization and Laravel package discovery after source exists
RUN composer dump-autoload --optimize --no-dev --no-interaction

# Ensure framework writeable dirs exist and have correct permissions
RUN mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Render provides PORT; default to 10000 for local container runs
ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT}"]
