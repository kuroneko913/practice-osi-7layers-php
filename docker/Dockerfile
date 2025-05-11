FROM php:8.4-cli

WORKDIR /app

# 必要なパッケージとzip拡張
RUN apt-get update \
    && apt-get install -y unzip git libzip-dev bash procps \
    && docker-php-ext-install zip

RUN echo "date.timezone=Asia/Tokyo" > /usr/local/etc/php/conf.d/timezone.ini

COPY composer.json .
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY src/ ./src/
RUN composer install

CMD ["php", "src/index.php"]
