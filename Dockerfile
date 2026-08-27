# FROM php:8.2-fpm

# # Install system dependencies
# RUN apt-get update && apt-get install -y \
#     git curl libpng-dev libonig-dev libxml2-dev zip unzip libzip-dev

# # Install PHP extensions li Laravel 3adatan kayحتاجhoم
# RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# # Install Composer
# COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# WORKDIR /var/www

# # Copy composer files awlan (bach يستفيد mn Docker cache)
# COPY composer.json composer.lock ./

# # Install dependencies b ignore-platform-reqs (bach تافادى platform mismatches)
# RUN composer install --optimize-autoloader --no-dev --ignore-platform-reqs --no-scripts

# # Copy baqi l-project
# COPY . .

# # Zid artisan commands ila khassين (optional)
# # RUN php artisan config:cache

# EXPOSE 8000

# CMD php artisan serve --host=0.0.0.0 --port=8000
FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install \
    --optimize-autoloader \
    --no-dev \
    --no-scripts

# Copy project
COPY . .

EXPOSE 8000

CMD php artisan serve --host=0.0.0.0 --port=8000
