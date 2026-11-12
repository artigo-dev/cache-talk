# PHP 8.5 CLI with what php-library and the demos need. The code is copied
# into the image rather than bind-mounted: autoloading over a Windows bind
# mount costs every worker process milliseconds per file.
FROM php:8.5-cli

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions redis apcu zstd pcntl intl yaml @composer \
	&& apt-get update \
	&& apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/*

COPY docker/php/talk.ini /usr/local/etc/php/conf.d/talk.ini

WORKDIR /app/project

COPY project/composer.json project/composer.lock* ./
RUN composer install --no-interaction --no-progress --no-autoloader --prefer-dist

COPY project/ ./
COPY project/.env.docker ./.env
COPY site/ /app/site/
RUN composer dump-autoload --optimize --no-interaction

EXPOSE 8080
# the deck from site/, the live API (/api/*) from the application
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/site", "/app/project/router.php"]
