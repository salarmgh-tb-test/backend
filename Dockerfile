FROM php:8.5.0-fpm-alpine3.23 AS base

WORKDIR /var/www

RUN apk add --no-cache \
        libpq \
        icu-libs \
        oniguruma \
        libzip && \
        rmdir /var/www/html && \
        chown -R www-data:www-data /var/www

RUN apk add --no-cache --virtual .build-deps \
        unzip \
        git \
        libpq-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        $PHPIZE_DEPS && \
    docker-php-ext-install pdo pdo_pgsql intl && \
    apk del .build-deps

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

FROM base AS php-fpm

ARG APP_ENV=production
ARG APP_DEBUG=false
ARG APP_URL=http://localhost
ENV APP_ENV=${APP_ENV} \
    APP_DEBUG=${APP_DEBUG} \
    APP_URL=${APP_URL}

USER www-data

COPY --chown=www-data:www-data composer.json composer.lock ./

RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction \
  && rm -rf /home/www-data/.composer

COPY --chown=www-data:www-data . .

RUN composer run-script post-autoload-dump --no-interaction

EXPOSE 9000

CMD ["php-fpm"]

FROM nginx:1.29.4-alpine3.23 AS nginx

RUN mkdir -p /var/cache/nginx/client_temp \
    /var/cache/nginx/proxy_temp \
    /var/cache/nginx/fastcgi_temp \
    /var/cache/nginx/uwsgi_temp \
    /var/cache/nginx/scgi_temp \
    /var/log/nginx \
    /var/run/nginx && \
    chown -R nginx:nginx /var/cache/nginx /var/log/nginx /var/run/nginx

USER nginx

WORKDIR /var/www

COPY --from=php-fpm --chown=nginx:nginx /var/www /var/www
COPY nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]