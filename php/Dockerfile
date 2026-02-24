FROM php:8.2-fpm

# 安裝系統套件
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libssl-dev \
    unzip \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

# 安裝 PHP 擴展 (Laravel 必需)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        xml \
        bcmath \
        opcache \
        pcntl

# 安裝 Redis PHP 擴展
RUN pecl install redis && docker-php-ext-enable redis

# 安裝 Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
