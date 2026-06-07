FROM php:8.2-cli-alpine

RUN apk add --no-cache unzip git     && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY composer.json phpunit.xml ./
RUN composer install --no-interaction --prefer-dist

COPY . .
CMD ["php", "-v"]
