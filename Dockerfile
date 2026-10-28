FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts

COPY . .
RUN composer dump-autoload

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]
