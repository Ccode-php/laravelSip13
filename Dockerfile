FROM php:8.3-fpm

# Asterisk-cli bilan ishlash uchun curl va kerakli kutubxonalar
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    zip \
    && rm -rf /var/lib/apt/lists/*

ENV TZ=Asia/Tashkent

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install \
    --optimize-autoloader \
    --no-interaction

# K3s ichida hostPath (fayl tizimi) bilan permission muammosi bo'lmasligi uchun
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]