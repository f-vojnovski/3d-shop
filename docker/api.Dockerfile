FROM php:8.4-cli-bookworm

# zip is not optional here: bundles are read with ZipArchive. The docker CLI is
# installed because the worker starts the render container as a sibling.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev ca-certificates curl gnupg \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip gd bcmath pcntl \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian bookworm stable" \
       > /etc/apt/sources.list.d/docker.list \
    && apt-get update && apt-get install -y --no-install-recommends docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY 3d-shop-api/composer.json 3d-shop-api/composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY 3d-shop-api/ ./

# .dockerignore keeps the developer's storage out of the image, so the tree
# Laravel expects is rebuilt here rather than copied from someone's machine.
RUN mkdir -p storage/app/private storage/logs bootstrap/cache \
      storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chmod -R 0777 storage bootstrap/cache \
    && composer dump-autoload --optimize

COPY docker/api-entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
