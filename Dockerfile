# ---- Stage 1: install YSMS Composer dependencies (phpoffice/phpspreadsheet, phpmailer) ----
# phpspreadsheet requires the PHP gd extension at install-check time, so it must
# be present in this build stage too, not just the final image.
# Using "composer update" (not "install") since composer.lock isn't kept in sync here —
# Cloud Build has internet access to resolve fresh from Packagist.
FROM composer:2-php8.3 AS vendor
WORKDIR /app
RUN apk add --no-cache libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd
COPY YSMS/composer.json ./
RUN composer update --no-dev --no-interaction --optimize-autoloader

# ---- Stage 2: application image ----
FROM php:8.3-apache

# Install MySQL/PDO support + gd (needed at runtime by phpspreadsheet for image handling)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure Apache to listen on Cloud Run's port
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf && \
    sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy application
COPY . /var/www/html/

# Copy YSMS vendor dependencies built in stage 1
COPY --from=vendor /app/vendor /var/www/html/YSMS/vendor

# Set permissions (www-data needs write access to upload directories)
RUN chown -R www-data:www-data /var/www/html && \
    mkdir -p /var/www/html/assets/uploads /var/www/html/YSMS/uploads && \
    chmod -R 775 /var/www/html/assets/uploads /var/www/html/YSMS/uploads

# Cloud Run uses port 8080
EXPOSE 8080
