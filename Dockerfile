FROM php:8.2-apache

ENV TZ=Asia/Shanghai

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        unzip \
        tzdata \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone \
    && printf 'date.timezone=%s\n' "$TZ" > /usr/local/etc/php/conf.d/timezone.ini \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p data logs qrcodes config \
    && chown -R www-data:www-data /var/www/html \
    && find data logs qrcodes config -type d -exec chmod 775 {} \;

EXPOSE 80

CMD ["apache2-foreground"]
