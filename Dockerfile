# ---- Stage 1: Build frontend assets ----
FROM node:25-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit
COPY vite.config.js tailwind.config.js ./
COPY resources/ resources/
RUN npm run build

# ---- Stage 2: Install PHP dependencies ----
FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize

# ---- Stage 3: Production image ----
FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    libsodium-dev \
    libpq-dev \
    icu-dev \
    && docker-php-ext-install \
    pdo_pgsql \
    sodium \
    intl \
    opcache \
    && rm -rf /var/cache/apk/*

# Copy PHP config
COPY docker/php.ini /usr/local/etc/php/conf.d/99-midori.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set working directory
WORKDIR /var/www/html

# Copy application
COPY --from=composer /app/vendor vendor/
COPY . .
COPY --from=frontend /app/public/build public/build/

# Set permissions: www-data necesita escribir storage/cache y los
# directorios runtime de nginx/supervisor para poder ejecutarse sin root.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && mkdir -p /var/lib/nginx/tmp /var/lib/nginx/logs /run/nginx /var/log/nginx /var/log/supervisor \
    && chown -R www-data:www-data /var/lib/nginx /run/nginx /var/log/nginx /var/log/supervisor \
    && touch /var/log/supervisord.log /var/run/supervisord.pid \
    && chown www-data:www-data /var/log/supervisord.log /var/run/supervisord.pid

# Remove SQLite database if exists (we use PostgreSQL)
RUN rm -f database/database.sqlite

# Drop privileges: el master de supervisord (y por tanto nginx, php-fpm,
# queue worker y scheduler) corre como www-data, no como root. Cualquier
# RCE en la app no obtiene shell root dentro del contenedor.
USER www-data

# Puerto no privilegiado: www-data no puede bindear <1024. El mapeo
# externo en docker-compose.yml apunta al puerto 8080 del contenedor.
EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
