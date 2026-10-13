FROM dunglas/frankenphp:1.13.1-php8.5-trixie

RUN install-php-extensions pdo_mysql redis gd mbstring zip opcache exif

# Node.js 24 LTS for the ssr service (Vite 8 requires Node >= 20.19); the base
# image ships no Node. Pinned to match the host toolchain.
RUN curl -fsSL https://nodejs.org/dist/v24.21.0/node-v24.21.0-linux-x64.tar.gz -o /tmp/node.tar.gz \
    && tar -xzf /tmp/node.tar.gz -C /usr/local --strip-components=1 \
    && rm -f /tmp/node.tar.gz \
    && node --version

# Composer and the image's vendor tree: the ssr service runs artisan in-image
# (wayfinder:generate), and the PRD's .dockerignore keeps vendor out of the
# context, so it is installed here. The dev bind mount provides vendor for
# app/worker.
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet \
    && rm -f /tmp/composer-setup.php \
    && composer --version

WORKDIR /app

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

COPY . /app

RUN composer install --no-dev --no-interaction --no-progress

# Linux node_modules for the ssr service, which runs from image code (the dev
# bind mount would otherwise shadow this with the host's Windows binaries).
RUN npm ci --no-audit --no-fund

# The image's default Caddyfile path is /etc/frankenphp/Caddyfile (its default
# CMD points there); /etc/caddy/Caddyfile would never be loaded.
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
