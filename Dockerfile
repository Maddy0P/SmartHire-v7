# SmartHire v7 — production image (PHP 8.2 + Apache) for Render / any Docker host
FROM php:8.2-apache

# System libs for the zip + PostgreSQL extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpq-dev unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip \
    && docker-php-ext-enable pdo_pgsql pgsql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: enable rewrite, headers, deflate (gzip), expires, and allow .htaccess
RUN a2enmod rewrite headers deflate expires

# Apache vhost + production PHP settings
COPY deploy/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.prod.ini /usr/local/etc/php/conf.d/zz-smarthire.ini

# App code (respects .dockerignore — git/, docs/, tests/, database/ are excluded)
WORKDIR /var/www/html
COPY . /var/www/html

# Writable runtime dirs (uploads is mounted as a Render Disk in production)
RUN mkdir -p uploads/resumes logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 uploads logs \
    && chmod 644 .htaccess uploads/.htaccess

# Render injects $PORT at runtime; Apache expands ${PORT} from the process env.
# Default to 8080 so the container is runnable without Render's injection.
ENV PORT=8080
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf
EXPOSE 8080

CMD ["apache2-foreground"]
