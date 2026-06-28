#!/bin/sh
set -eu

cd /app

echo "→ Starting container..."

# Corriger les permissions var/ (chown au build ne survit pas à certains volumes)
mkdir -p var/cache var/log var/share
chown -R www-data:www-data var
chmod -R ug+rwX var
chown -R www-data:www-data /var/log/apache2

# Migrations Doctrine — en www-data (/app appartient à www-data)
echo "→ Running migrations..."
gosu www-data php bin/console doctrine:migrations:migrate \
    --no-interaction \
    --allow-no-migration \
    --env=prod

# Pas de cache:clear ni cache:warmup ici : le cache est chaud dans l'image

# Drop définitif vers www-data — Apache n'a pas besoin de root sur le port 8080
echo "→ Ready."
exec gosu www-data "$@"
