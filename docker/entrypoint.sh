#!/bin/sh
set -e

# Ensure storage and database folders exist and have write permissions
mkdir -p /var/www/html/database
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/logs

touch /var/www/html/database/database.sqlite
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Run Laravel optimizations and DB migration
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true
php artisan storage:link --force || true
php artisan migrate --force --seed || true

# Start Supervisor
exec /usr/bin/supervisord -c /etc/supervisord.conf
