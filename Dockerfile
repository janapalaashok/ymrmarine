# ---- Stage 1: install YSMS Composer dependencies (phpoffice/phpspreadsheet) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY YSMS/composer.json YSMS/composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

# ---- Stage 2: application image ----
FROM php:8.2-apache

# Install MySQL/PDO support
RUN docker-php-ext-install pdo pdo_mysql

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