#!/bin/sh
set -eu

cd /app

echo "→ Starting container..."

# Corriger les permissions var/ (chown au build ne survit pas à certains volumes)
mkdir -p var/cache var/log var/share
chown -R www-data:www-data var
chmod -R ug+rwX var

# Migrations Doctrine
echo "→ Running migrations..."
php bin/console doctrine:migrations:migrate \
    --no-interaction \
    --allow-no-migration \
    --env=prod

# Pas de cache:clear ni cache:warmup ici : le cache est chaud dans l'image

echo "→ Ready."
exec "$@"
