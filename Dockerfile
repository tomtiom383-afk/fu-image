FROM php:8.1-fpm-alpine

LABEL org.opencontainers.image.title="FuImage"
LABEL org.opencontainers.image.description="A lightweight self-hosted image hosting app"
LABEL org.opencontainers.image.source="https://github.com/tomtiom383-afk/fu-image"

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
        freetype \
        libpng \
        libjpeg-turbo \
        libwebp \
        linux-headers \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-enable gd

# Install optional apcu for caching
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .build-deps

WORKDIR /var/www/html

# Copy project files
COPY . .

# Set up permissions
RUN chown -R www-data:www-data /var/www/html/config \
    && chmod +x /var/www/html/docker-entrypoint.sh

# Data volumes for persistence
VOLUME ["/data/images", "/data/meta"]

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["php-fpm"]

HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);" || exit 1
