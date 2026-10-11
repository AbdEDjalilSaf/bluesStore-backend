#!/usr/bin/env sh
set -e

# Ensure the framework writable directories exist inside the container.
mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# When using SQLite, make sure the database file exists before migrating.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE:-database/database.sqlite}"
fi

# Apply migrations and seed reference/demo data. The seeders use
# firstOrCreate, so they are safe to run on every boot.
php artisan migrate --force
php artisan db:seed --force
php artisan db:seed --class=DemoProductSeeder --force

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
