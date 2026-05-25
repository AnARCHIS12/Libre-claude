FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libsqlite3-dev \
        ca-certificates \
    && docker-php-ext-install curl pdo pdo_sqlite \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN mkdir -p /var/www/html/data /var/www/html/sandbox \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/sandbox \
    && cp /var/www/html/docker-entrypoint.sh /usr/local/bin/libre-claude-entrypoint \
    && chmod +x /usr/local/bin/libre-claude-entrypoint \
    && find /var/www/html -type f -name "*.php" -exec php -l {} \; >/tmp/php-lint.log \
    && rm -f /tmp/php-lint.log

EXPOSE 80

ENTRYPOINT ["libre-claude-entrypoint"]
CMD ["apache2-foreground"]
