FROM php:8.2-fpm

# 安裝系統套件（含 PostgreSQL 客戶端函式庫）
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libssl-dev \
    libpq-dev \
    libonig-dev \
    unzip \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

# 安裝 PHP 擴展
# pdo_pgsql / pgsql 取代原本的 pdo_mysql
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        zip \
        gd \
        xml \
        bcmath \
        opcache \
        pcntl

# 安裝 Redis PHP 擴展
RUN pecl install redis && docker-php-ext-enable redis

# OPcache 設定（生產環境最佳化）
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini

# 安裝 Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
