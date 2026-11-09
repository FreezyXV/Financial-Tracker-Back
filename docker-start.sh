#!/bin/sh
# Démarrage production : migrations avec reprises (réveil de Neon), puis serveur HTTP.
# Cache applicatif (limitation des tentatives de connexion…) sur disque local plutôt qu'en base :
# évite plusieurs allers-retours réseau vers PostgreSQL à chaque connexion. Instance unique.
export CACHE_STORE=file
php artisan config:clear
mkdir -p storage/logs
ok=0
for i in 1 2 3 4 5; do
  if php artisan migrate --force > storage/logs/boot-migrate.log 2>&1; then ok=1; break; fi
  echo "Migration attempt $i failed, retrying in 5s"; sleep 5
done
[ "$ok" = 1 ] && echo "Migrations OK" || { echo "MIGRATIONS FAILED"; cat storage/logs/boot-migrate.log; }
[ "$ok" = 1 ] && php artisan finance:purge-test-accounts
# Compte de démonstration (demo@finance.test) recréé à chaque déploiement ; SEED_DEMO=false pour désactiver.
if [ "$ok" = 1 ] && [ "${SEED_DEMO:-true}" != "false" ]; then
  php artisan db:seed --force && echo "Demo account seeded" || echo "Demo seed failed"
fi
# Caches de production (variables d'environnement disponibles à ce stade).
php artisan config:cache && php artisan route:cache && php artisan event:cache || echo "Cache warmup failed"
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
