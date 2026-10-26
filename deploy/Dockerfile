FROM php:8.3-fpm-alpine

# Install system deps + PHP extensions
RUN apk add --no-cache \
      nginx \
      supervisor \
      curl \
      libpng-dev \
      libzip-dev \
      icu-dev \
      oniguruma-dev \
      freetype-dev \
      libjpeg-turbo-dev \
   && docker-php-ext-configure gd --with-freetype --with-jpeg \
   && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        fileinfo \
        zip \
        bcmath \
        intl \
        gd \
        opcache

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'upload_max_filesize = 25M'; \
      echo 'post_max_size = 26M'; \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 60'; \
      echo 'expose_php = Off'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.memory_consumption = 128'; \
   } >> "$PHP_INI_DIR/php.ini"

# Nginx configuration
RUN rm -f /etc/nginx/http.d/default.conf
COPY deploy/nginx-app.conf /etc/nginx/http.d/app.conf

# Supervisor (manages nginx + php-fpm together)
COPY deploy/supervisord.conf /etc/supervisord.conf

# App source
WORKDIR /var/www/html
COPY backend/ /var/www/html/backend/
COPY frontend/ /var/www/html/frontend/

# Storage directory. The entrypoint fixes ownership again when a volume is mounted.
COPY deploy/docker-entrypoint.sh /docker-entrypoint.sh
RUN mkdir -p /var/www/html/storage/documents /var/www/html/backend/storage/documents \
 && chown -R www-data:www-data /var/www/html/storage /var/www/html/backend/storage \
 && chmod -R 775 /var/www/html/storage /var/www/html/backend/storage \
 && chmod +x /docker-entrypoint.sh

# Nginx needs to write logs
RUN chown -R nginx:nginx /var/lib/nginx \
 && chmod -R 755 /var/lib/nginx

EXPOSE 80
ENTRYPOINT ["/docker-entrypoint.sh"]
