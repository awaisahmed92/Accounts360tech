#!/bin/sh
set -e

# The invoice folder must be writable by PHP. A mounted volume is root-owned until this runs.
mkdir -p /var/www/html/storage/documents \
  /var/www/html/backend/storage/documents \
  /var/www/html/backend/storage/captcha
chown -R www-data:www-data /var/www/html/storage /var/www/html/backend/storage
chmod -R 775 /var/www/html/storage /var/www/html/backend/storage

exec /usr/bin/supervisord -c /etc/supervisord.conf
