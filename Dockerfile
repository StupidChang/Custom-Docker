FROM php:8.2-cli

# 安裝必要工具與 PHP 擴展
RUN apt-get update && apt-get install -y libssl-dev libzip-dev unzip curl \
    && docker-php-ext-install pcntl sockets

# 安裝 Composer
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer

# 預設工作目錄
WORKDIR /app

# 安裝套件（預設會從 volume 掛載 .）
COPY composer.json ./
RUN composer install
