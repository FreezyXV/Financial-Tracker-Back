<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Diagnostic de déploiement : état de l'application et de la base, sans aucun secret. */
class HealthController extends Controller
{
    public function __invoke()
    {
        $url = (string) config('database.connections.pgsql.url');
        $db = [
            'connection' => config('database.default'),
            'host' => $url ? parse_url($url, PHP_URL_HOST) : null,
            'pooled' => str_contains($url, '-pooler'),
        ];
        try {
            $host = (string) $db['host'];
            $t = microtime(true);
            $db['dns'] = $host ? ['ipv4' => count(gethostbynamel($host) ?: []), 'ipv6' => count(@dns_get_record($host, DNS_AAAA) ?: [])] : null;
            $db['dns_ms'] = round((microtime(true) - $t) * 1000, 1);
            $t = microtime(true);
            DB::select('select 1');
            $db['status'] = 'ok';
            $db['latency_ms'] = round((microtime(true) - $t) * 1000, 1); // connexion (si nouvelle) + requête
            $t = microtime(true);
            DB::select('select 1');
            $db['query_ms'] = round((microtime(true) - $t) * 1000, 1); // aller-retour seul
            $db['persistent'] = (bool) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_PERSISTENT);
            $db['migrated'] = Schema::hasTable('transactions');
            $log = storage_path('logs/boot-migrate.log');
            if (! $db['migrated'] && is_file($log)) {
                $db['migrate_log'] = mb_substr(preg_replace('#\w+://\S+#', '[url]', (string) file_get_contents($log)), 0, 900);
            }
        } catch (Throwable $e) {
            $db['status'] = 'error';
            $db['error'] = class_basename($e).(preg_match('/SQLSTATE\[\w+\]/', $e->getMessage(), $m) ? ' '.$m[0] : '');
            if (preg_match('/failed: (.{0,160})/s', $e->getMessage(), $m)) {
                $db['detail'] = preg_replace('#\w+://\S+#', '[url]', $m[1]);
            }
        }

        return response()->json([
            'app' => 'ok',
            'env' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'key_set' => (bool) config('app.key'),
            'opcache' => function_exists('opcache_get_status') && (bool) (opcache_get_status(false)['opcache_enabled'] ?? false),
            'cors_origins' => config('cors.allowed_origins'),
            'db' => $db,
        ], $db['status'] === 'ok' ? 200 : 503);
    }
}
