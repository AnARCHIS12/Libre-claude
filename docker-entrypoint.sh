#!/bin/sh
set -e

mkdir -p /var/www/html/data /var/www/html/sandbox
chown -R www-data:www-data /var/www/html/data /var/www/html/sandbox

exec docker-php-entrypoint "$@"

