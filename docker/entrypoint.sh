#!/bin/sh
set -eu

cd /app

echo "→ Starting container..."

# Corriger les permissions var/ (chown au build ne survit pas à certains volumes)
mkdir -p var/cache var/log var/share
chown -R www-data:www-data var
chmod -R ug+rwX var

# Migrations Doctrine — en www-data (/app appartient à www-data)
echo "→ Running migrations..."
gosu www-data php bin/console doctrine:migrations:migrate \
    --no-interaction \
    --allow-no-migration \
    --env=prod

# Pas de cache:clear ni cache:warmup ici : le cache est chaud dans l'image

# Apache : le master tourne en root (ouvre le socket + les logs /dev/stderr),
# les workers passent en www-data via la directive `User www-data` de l'image.
# C'est le PHP (workers) qui s'exécute non-root — pas besoin de gosu ici, sinon
# le master www-data ne peut pas rouvrir le pipe stderr appartenant à root (EACCES).
echo "→ Ready."
exec "$@"
