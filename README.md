# Registre — API (Laravel 12)

API REST du gestionnaire de finances personnelles **Registre** : comptes, opérations, budgets, investissements (PEA, CTO, crypto), bilans mensuels/annuels, objectifs, échéances et projection.
Front Angular : [Financial-Tracker-Front](https://github.com/FreezyXV/Financial-Tracker-Front).

## Démarrage

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed   # données de démo : demo@finance.test / password
php artisan serve                  # http://127.0.0.1:8000
```

Tâches planifiées (`php artisan schedule:work` en local, cron `schedule:run` en production) :

| Commande | Rôle |
|---|---|
| `finance:post-recurring` | Passe en écriture les échéances arrivées à terme (6 h) |
| `finance:refresh-prices` | Met à jour les cours Yahoo Finance / CoinGecko (jours ouvrés) |

Tests : `php artisan test` (20 tests : PRU, devises, plafond PEA, XIRR, import, bilans, alertes, cloisonnement des données).

## Architecture

| Élément | Rôle |
|---|---|
| `app/Services/WealthEngine.php` | Moteur de calcul : soldes, positions au prix moyen pondéré, valorisation EUR, patrimoine à n'importe quelle date, historique, TRI |
| `app/Services/ReportService.php` | Bilans mensuels/annuels, budgets (report, projection), règle 50/30/20, constats automatiques |
| `app/Services/Categorizer.php` | Catégorisation : règles « mot1\|mot2 » puis apprentissage sur l'historique |
| `app/Services/RecurringService.php` | Échéances, projection 30 jours, détection d'abonnements |
| `app/Services/PriceService.php` | Cotations, historique 2 ans, recherche d'instruments, change |
| `app/Support/Xirr.php` | Taux de rendement interne (Newton + dichotomie) |

Conventions : montants de transactions toujours positifs (le sens vient du `type`), un virement vers un PEA/CTO est un **apport**, les achats/ventes sont des `investment_operations` qui mouvementent les espèces de l'enveloppe.

## Endpoints (Bearer token Sanctum)

`POST /api/register`, `POST /api/login`, `GET|PUT /api/me`, `POST /api/logout`

| Synthèses | Ressources (CRUD) | Actions |
|---|---|---|
| `GET /dashboard` | `accounts`, `categories`, `transactions` | `POST /transactions/import`, `POST /transactions/bulk`, `GET /transactions/export` |
| `GET /reports/monthly?month=YYYY-MM` | `budgets?month=`, `goals`, `rules` | `POST /rules/apply` |
| `GET /reports/yearly?year=` | `recurring`, `assets`, `operations` | `POST /recurring/post`, `GET /recurring/suggestions` |
| `GET /net-worth?months=`, `GET /portfolio` | `alerts` (lecture, lu, suppression) | `POST /assets/refresh`, `GET /assets/search?q=`, `POST /alerts/read-all` |
| `GET /simulator/defaults` | | |

## Production

- `DB_CONNECTION=pgsql` ou `mysql`, `APP_ENV=production`, `APP_DEBUG=false`.
- `CORS_ALLOWED_ORIGINS` = domaine du front.
- Cron : `* * * * * php artisan schedule:run`.
