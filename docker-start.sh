#!/bin/sh
# Démarrage production : migrations avec reprises (réveil de Neon), puis serveur HTTP.
php artisan config:clear
mkdir -p storage/logs
ok=0
for i in 1 2 3 4 5; do
  if php artisan migrate --force > storage/logs/boot-migrate.log 2>&1; then ok=1; break; fi
  echo "Migration attempt $i failed, retrying in 5s"; sleep 5
done
[ "$ok" = 1 ] && echo "Migrations OK" || { echo "MIGRATIONS FAILED"; cat storage/logs/boot-migrate.log; }
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
