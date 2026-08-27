FROM php:8.2-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --optimize-autoloader --no-dev

EXPOSE 8000

CMD php artisan serve --host=0.0.0.0 --port=8000

RUN composer install --optimize-autoloader --no-dev --ignore-platform-reqs
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip
