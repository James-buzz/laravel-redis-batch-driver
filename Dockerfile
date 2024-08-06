FROM php:8.2-cli

# Install necessary PHP extensions and Redis
RUN apt-get update && apt-get install -y unzip libzip-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV PATH="${PATH}:/var/www/.composer/vendor/bin"

# Set the working directory
WORKDIR /app

# Copy the application code
COPY . /app

# Install PHP dependencies
RUN composer install

# Keep the container running
CMD ["tail", "-f", "/dev/null"]
