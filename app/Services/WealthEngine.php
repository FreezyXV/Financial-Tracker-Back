<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Support\Xirr;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de calcul patrimonial.
 *
 * Charge une seule fois toutes les données d'un utilisateur et calcule en mémoire :
 * soldes de comptes, positions (PRU / prix moyen pondéré), valorisation, patrimoine
 * net à n'importe quelle date, historique et performance (XIRR).
 *
 * Conventions :
 * - Les montants de transactions sont positifs ; le sens vient du type.
 * - Les dates sont manipulées en chaînes 'Y-m-d' (comparaison lexicographique).
 * - Toutes les valeurs restituées sont en EUR.
 */
class WealthEngine
{
    public const PEA_CEILING = 150000;

    /** Groupes d'affichage du patrimoine. */
    public const GROUPS = [
        'checking' => 'liquidites', 'cash' => 'liquidites',
        'savings' => 'epargne', 'livret_a' => 'epargne', 'ldds' => 'epargne',
        'lep' => 'epargne', 'pel' => 'epargne',
        'pea' => 'investissements', 'pea_pme' => 'investissements', 'cto' => 'investissements',
        'assurance_vie' => 'investissements', 'per' => 'investissements',
        'crypto' => 'crypto',
        'real_estate' => 'immobilier',
        'loan' => 'dettes',
        'other' => 'autres',
    ];

    /** @var array<int, Account> */
    public array $accounts = [];

    /** @var array<int, object> */
    public array $transactions = [];

    /** @var array<int, object> */
    public array $operations = [];

    /** @var array<int, object> */
    public array $assets = [];

    /** @var array<int, array<int, array{0:string,1:float,2:float}>> asset_id => [[date, price, fx]] */
    private array $priceHistory = [];

    private string $today;

    /** @var array<int, array{tx: array{0: string[], 1: float[]}, op: array{0: string[], 1: float[]}}> séries cumulées par compte */
    private array $series = [];

    /** @var array<int, array<int, array{0:string,1:float,2:float}>> derniers prix d'exécution par actif */
    private array $tradePrices = [];

    /** Mémoïsation des calculs coûteux (clé = date, compte…). */
    private array $memo = [];

    public function __construct(public User $user)
    {
        $this->today = Carbon::today()->format('Y-m-d');
        $this->load();
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    private function load(): void
    {
        foreach (Account::where('user_id', $this->user->id)->orderBy('id')->get() as $a) {
            $this->accounts[$a->id] = $a;
        }

        $this->transactions = DB::table('transactions')
            ->where('user_id', $this->user->id)
            ->orderBy('date')->orderBy('id')
            ->get(['id', 'account_id', 'transfer_account_id', 'category_id', 'type', 'amount', 'date', 'description'])
            ->map(function ($t) {
                $t->amount = (float) $t->amount;
                $t->date = substr((string) $t->date, 0, 10);

                return $t;
            })->all();

        $this->operations = DB::table('investment_operations')
            ->where('user_id', $this->user->id)
            ->orderBy('date')->orderBy('id')
            ->get()
            ->map(function ($o) {
                foreach (['quantity', 'price', 'fx_rate', 'fees', 'amount'] as $f) {
                    $o->$f = (float) $o->$f;
                }
                $o->date = substr((string) $o->date, 0, 10);

                return $o;
            })->all();

        foreach (DB::table('assets')->where('user_id', $this->user->id)->get() as $asset) {
            $asset->current_price = (float) $asset->current_price;
            $asset->fx_rate = (float) $asset->fx_rate ?: 1.0;
            $this->assets[$asset->id] = $asset;
        }

        if ($this->assets) {
            $rows = DB::table('asset_prices')
                ->whereIn('asset_id', array_keys($this->assets))
                ->orderBy('date')
                ->get(['asset_id', 'date', 'price', 'fx_rate']);
            foreach ($rows as $r) {
                $this->priceHistory[$r->asset_id][] = [
                    substr((string) $r->date, 0, 10), (float) $r->price, (float) $r->fx_rate ?: 1.0,
                ];
            }
        }
        $this->buildIndex();
    }

    /**
     * Pré-calcule, pour chaque compte, les flux cumulés datés (transactions et opérations).
     * Un solde à une date devient une recherche dichotomique : O(log n) au lieu de O(n).
     */
    private function buildIndex(): void
    {
        $raw = [];
        foreach ($this->transactions as $t) {
            $ids = $t->type === 'transfer' ? [(int) $t->account_id, (int) $t->transfer_account_id] : [(int) $t->account_id];
            foreach (array_unique(array_filter($ids)) as $id) {
                $raw[$id]['tx'][] = [$t->date, self::txDelta($t, $id)];
            }
        }
        foreach ($this->operations as $o) {
            $raw[(int) $o->account_id]['op'][] = [$o->date, $this->opCashImpact($o)];
            if ($o->asset_id && in_array($o->type, ['buy', 'sell'], true)) {
                $this->tradePrices[(int) $o->asset_id][] = [$o->date, $o->price, $o->fx_rate];
            }
        }
        foreach ($raw as $id => $kinds) {
            foreach (['tx', 'op'] as $kind) {
                $dates = [];
                $cum = [];
                $sum = 0.0;
                foreach ($kinds[$kind] ?? [] as [$date, $delta]) {
                    $sum += $delta;
                    $n = count($dates);
                    if ($n && $dates[$n - 1] === $date) {
                        $cum[$n - 1] = $sum; // même jour : on garde le cumul de fin de journée
                    } else {
                        $dates[] = $date;
                        $cum[] = $sum;
                    }
                }
                $this->series[$id][$kind] = [$dates, $cum];
            }
        }
    }

    /** Index du dernier élément dont la date est <= $at (-1 si aucun). */
    private static function lastIndexAtOrBefore(array $dates, string $at, int $key = -1): int
    {
        $lo = 0;
        $hi = count($dates) - 1;
        $found = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $d = $key < 0 ? $dates[$mid] : $dates[$mid][$key];
            if ($d <= $at) {
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $found;
    }

    private function cumulative(int $accountId, string $kind, string $at): float
    {
        [$dates, $cum] = $this->series[$accountId][$kind] ?? [[], []];
        $i = self::lastIndexAtOrBefore($dates, $at);

        return $i < 0 ? 0.0 : $cum[$i];
    }

    private function remember(string $key, callable $fn): mixed
    {
        return array_key_exists($key, $this->memo) ? $this->memo[$key] : $this->memo[$key] = $fn();
    }

    /* ------------------------------------------------------------------ */
    /*  Cash & soldes */
    /* ------------------------------------------------------------------ */

    /** Variation de cash d'une transaction pour un compte donné. */
    public static function txDelta(object $t, int $accountId): float
    {
        if ($t->type === 'transfer') {
            $delta = 0.0;
            if ((int) $t->account_id === $accountId) {
                $delta -= $t->amount;
            }
            if ((int) $t->transfer_account_id === $accountId) {
                $delta += $t->amount;
            }

            return $delta;
        }
        if ((int) $t->account_id !== $accountId) {
            return 0.0;
        }

        return $t->type === 'income' ? $t->amount : -$t->amount;
    }

    private function openedBy(Account $a, string $at): bool
    {
        return ! $a->opened_at || $a->opened_at->format('Y-m-d') <= $at;
    }

    public function cashBalance(Account $a, ?string $at = null): float
    {
        $at ??= $this->today;
        $balance = ($this->openedBy($a, $at) ? $a->opening_balance : 0.0)
            + $this->cumulative($a->id, 'tx', $at)
            + $this->cumulative($a->id, 'op', $at);

        return round($balance, 2);
    }

    private function opCashImpact(object $o): float
    {
        return match ($o->type) {
            'buy' => -($o->quantity * $o->price * $o->fx_rate + $o->fees),
            'sell' => $o->quantity * $o->price * $o->fx_rate - $o->fees,
            'dividend', 'interest' => $o->amount,
            'fee' => -$o->amount,
            default => 0.0,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Prix */
    /* ------------------------------------------------------------------ */

    /** @return array{0: float, 1: float} [prix devise actif, fx vers EUR] */
    public function priceAt(int $assetId, ?string $at = null): array
    {
        $asset = $this->assets[$assetId] ?? null;
        if (! $asset) {
            return [0.0, 1.0];
        }
        if ($at === null || $at >= $this->today) {
            return [$asset->current_price, $asset->fx_rate];
        }
        $history = $this->priceHistory[$assetId] ?? [];
        $i = self::lastIndexAtOrBefore($history, $at, 0);
        if ($i >= 0) {
            return [$history[$i][1], $history[$i][2]];
        }
        // Repli : dernier prix d'exécution connu avant la date.
        $trades = $this->tradePrices[$assetId] ?? [];
        $j = self::lastIndexAtOrBefore($trades, $at, 0);
        $last = $j >= 0 ? [$trades[$j][1], $trades[$j][2]] : null;

        return $last ?? [$asset->current_price, $asset->fx_rate];
    }

    /* ------------------------------------------------------------------ */
    /*  Positions */
    /* ------------------------------------------------------------------ */

    /**
     * Positions par (compte, actif) à une date, méthode du prix moyen pondéré.
     *
     * @return array<int, array<string, mixed>>
     */
    public function holdings(?string $at = null, ?int $accountId = null, bool $includeClosed = false): array
    {
        $all = $this->positionsAt($at ?? $this->today);

        return array_values(array_filter($all, fn ($p) => ($includeClosed || $p['quantity'] > 0)
            && (! $accountId || $p['account_id'] === $accountId)));
    }

    /** Toutes les positions (ouvertes et soldées) à une date, calculées une seule fois. */
    private function positionsAt(string $at): array
    {
        return $this->remember("positions|$at", fn () => $this->computePositions($at));
    }

    private function computePositions(string $at): array
    {
        $state = [];
        foreach ($this->operations as $o) {
            if ($o->date > $at) {
                break;
            }
            if (! $o->asset_id) {
                continue;
            }
            $key = $o->account_id.'-'.$o->asset_id;
            $s = &$state[$key];
            $s ??= [
                'account_id' => (int) $o->account_id, 'asset_id' => (int) $o->asset_id,
                'quantity' => 0.0, 'cost' => 0.0, 'realized' => 0.0, 'dividends' => 0.0,
                'fees' => 0.0, 'first_date' => $o->date, 'operations' => 0,
            ];
            $s['operations']++;
            switch ($o->type) {
                case 'buy':
                    $s['quantity'] += $o->quantity;
                    $s['cost'] += $o->quantity * $o->price * $o->fx_rate + $o->fees;
                    $s['fees'] += $o->fees;
                    break;
                case 'sell':
                    $avg = $s['quantity'] > 0 ? $s['cost'] / $s['quantity'] : 0;
                    $qty = min($o->quantity, $s['quantity']);
                    $proceeds = $o->quantity * $o->price * $o->fx_rate - $o->fees;
                    $s['realized'] += $proceeds - $avg * $qty;
                    $s['cost'] -= $avg * $qty;
                    $s['quantity'] -= $qty;
                    $s['fees'] += $o->fees;
                    if ($s['quantity'] < 1e-9) {
                        $s['quantity'] = 0.0;
                        $s['cost'] = 0.0;
                    }
                    break;
                case 'dividend':
                case 'interest':
                    $s['dividends'] += $o->amount;
                    break;
                case 'fee':
                    $s['fees'] += $o->amount;
                    break;
            }
            unset($s);
        }

        $positions = [];
        foreach ($state as $s) {
            $asset = $this->assets[$s['asset_id']] ?? null;
            [$price, $fx] = $this->priceAt($s['asset_id'], $at === $this->today ? null : $at);
            $value = $s['quantity'] * $price * $fx;
            $unrealized = $value - $s['cost'];
            $positions[] = [
                'account_id' => $s['account_id'],
                'account_name' => $this->accounts[$s['account_id']]->name ?? null,
                'asset_id' => $s['asset_id'],
                'symbol' => $asset->symbol ?? '?',
                'name' => $asset->name ?? '?',
                'asset_class' => $asset->asset_class ?? 'other',
                'region' => $asset->region ?? null,
                'sector' => $asset->sector ?? null,
                'currency' => $asset->currency ?? 'EUR',
                'quantity' => round($s['quantity'], 10),
                'avg_price' => $s['quantity'] > 0 ? round($s['cost'] / $s['quantity'] / ($fx ?: 1), 6) : 0,
                'avg_cost_eur' => $s['quantity'] > 0 ? round($s['cost'] / $s['quantity'], 6) : 0,
                'price' => round($price, 6),
                'fx_rate' => $fx,
                'cost' => round($s['cost'], 2),
                'value' => round($value, 2),
                'unrealized' => round($unrealized, 2),
                'unrealized_pct' => $s['cost'] > 0 ? round($unrealized / $s['cost'] * 100, 2) : 0,
                'realized' => round($s['realized'], 2),
                'dividends' => round($s['dividends'], 2),
                'fees' => round($s['fees'], 2),
                'first_date' => $s['first_date'],
                'price_updated_at' => $asset->price_updated_at ?? null,
            ];
        }
        usort($positions, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $positions;
    }

    /* ------------------------------------------------------------------ */
    /*  Valorisation */
    /* ------------------------------------------------------------------ */

    public function accountValue(Account $a, ?string $at = null): float
    {
        $at ??= $this->today;

        return $this->remember("value|{$a->id}|$at", fn () => $this->computeAccountValue($a, $at));
    }

    private function computeAccountValue(Account $a, string $at): float
    {
        if ($a->manual_value !== null) {
            return $this->openedBy($a, $at) ? (float) $a->manual_value : 0.0;
        }
        $value = $this->cashBalance($a, $at);
        if ($a->isInvestment()) {
            foreach ($this->holdings($at, $a->id) as $p) {
                $value += $p['value'];
            }
        }

        return round($value, 2);
    }

    /** Apports nets externes (versements - retraits) dans un compte jusqu'à une date. */
    public function contributions(Account $a, ?string $at = null): float
    {
        $at ??= $this->today;
        $total = ($this->openedBy($a, $at) ? $a->opening_balance : 0.0) + $this->cumulative($a->id, 'tx', $at);

        return round($total, 2);
    }

    /** Versements bruts (somme des entrées) — utile pour le plafond PEA. */
    public function grossDeposits(Account $a): float
    {
        $total = max(0, $a->opening_balance);
        foreach ($this->transactions as $t) {
            if ($t->type === 'transfer' && (int) $t->transfer_account_id === $a->id) {
                $total += $t->amount;
            } elseif ($t->type === 'income' && (int) $t->account_id === $a->id) {
                $total += $t->amount;
            }
        }

        return round($total, 2);
    }

    public function accountSummary(Account $a): array
    {
        $value = $this->accountValue($a);
        $summary = [
            'balance' => $this->cashBalance($a),
            'value' => $value,
            'group' => self::GROUPS[$a->type] ?? 'autres',
        ];
        if ($a->isInvestment()) {
            $contrib = $this->contributions($a);
            // Valeur saisie manuellement sans historique de versements : gain inconnu.
            if ($a->manual_value !== null && $contrib <= 0) {
                $contrib = $value;
            }
            $summary['contributions'] = $contrib;
            $summary['gain'] = round($value - $contrib, 2);
            $summary['gain_pct'] = $contrib > 0 ? round(($value - $contrib) / $contrib * 100, 2) : 0;
            $summary['xirr'] = $a->manual_value === null ? $this->xirr([$a->id]) : null;
            $summary['holdings_value'] = round($value - $summary['balance'], 2);
        }
        if (in_array($a->type, ['pea', 'pea_pme'], true)) {
            $ceiling = $a->type === 'pea' ? self::PEA_CEILING : 225000;
            $deposits = $this->grossDeposits($a);
            $summary['ceiling'] = $ceiling;
            $summary['deposits'] = $deposits;
            $summary['ceiling_remaining'] = max(0, $ceiling - $deposits);
        }
        if ($a->opened_at) {
            $years = $a->opened_at->diffInDays(Carbon::today()) / 365.25;
            $summary['age_years'] = round($years, 2);
            if (in_array($a->type, ['pea', 'pea_pme'], true)) {
                $summary['tax_free_date'] = $a->opened_at->copy()->addYears(5)->format('Y-m-d');
            }
            if ($a->type === 'assurance_vie') {
                $summary['tax_free_date'] = $a->opened_at->copy()->addYears(8)->format('Y-m-d');
            }
        }

        return $summary;
    }

    /** Patrimoine net et ventilations à une date. */
    public function netWorth(?string $at = null): array
    {
        $at ??= $this->today;

        return $this->remember("nw|$at", fn () => $this->computeNetWorth($at));
    }

    private function computeNetWorth(string $at): array
    {
        $assets = 0.0;
        $liabilities = 0.0;
        $byGroup = [];
        $byType = [];
        $byAccount = [];
        foreach ($this->accounts as $a) {
            if (! $a->include_in_net_worth) {
                continue;
            }
            $v = $this->accountValue($a, $at);
            if (abs($v) < 0.005) {
                continue;
            }
            $v < 0 ? $liabilities += -$v : $assets += $v;
            $g = self::GROUPS[$a->type] ?? 'autres';
            $byGroup[$g] = ($byGroup[$g] ?? 0) + $v;
            $byType[$a->type] = ($byType[$a->type] ?? 0) + $v;
            $byAccount[] = ['id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'value' => $v];
        }

        return [
            'date' => $at,
            'net_worth' => round($assets - $liabilities, 2),
            'assets' => round($assets, 2),
            'liabilities' => round($liabilities, 2),
            'by_group' => array_map(fn ($v) => round($v, 2), $byGroup),
            'by_type' => array_map(fn ($v) => round($v, 2), $byType),
            'by_account' => $byAccount,
        ];
    }

    /** Répartition par classe d'actif (positions + liquidités/épargne + immobilier). */
    public function allocation(): array
    {
        $alloc = [];
        foreach ($this->holdings() as $p) {
            if (! ($this->accounts[$p['account_id']]->include_in_net_worth ?? true)) {
                continue;
            }
            $alloc[$p['asset_class']] = ($alloc[$p['asset_class']] ?? 0) + $p['value'];
        }
        foreach ($this->accounts as $a) {
            if (! $a->include_in_net_worth) {
                continue;
            }
            if ($a->manual_value !== null) {
                $key = $a->type === 'real_estate' ? 'real_estate' : ($a->isInvestment() ? 'fund' : 'cash');
                $alloc[$key] = ($alloc[$key] ?? 0) + max(0, (float) $a->manual_value);

                continue;
            }
            $cash = $this->cashBalance($a);
            if ($cash > 0 && $a->type !== 'loan') {
                $alloc['cash'] = ($alloc['cash'] ?? 0) + $cash;
            }
        }
        arsort($alloc);

        return array_map(fn ($v) => round($v, 2), $alloc);
    }

    /**
     * Historique du patrimoine : un point par fin de mois + aujourd'hui.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $months = 12): array
    {
        $points = [];
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);
        for ($i = 0; $i < $months; $i++) {
            $d = $start->copy()->addMonths($i)->endOfMonth();
            $date = min($d->format('Y-m-d'), $this->today);
            $nw = $this->netWorth($date);
            $invested = 0.0;
            foreach ($this->accounts as $a) {
                if ($a->include_in_net_worth && $a->isInvestment()) {
                    $invested += $this->accountValue($a, $date);
                }
            }
            $points[] = [
                'date' => $date,
                'net_worth' => $nw['net_worth'],
                'assets' => $nw['assets'],
                'liabilities' => $nw['liabilities'],
                'investments' => round($invested, 2),
                'by_group' => $nw['by_group'],
            ];
        }

        return $points;
    }

    /* ------------------------------------------------------------------ */
    /*  Performance */
    /* ------------------------------------------------------------------ */

    /** Taux de rendement interne annualisé (XIRR) sur un ensemble de comptes, en %. */
    public function xirr(array $accountIds): ?float
    {
        $flows = [];
        $ids = array_map('intval', $accountIds);
        foreach ($ids as $id) {
            $a = $this->accounts[$id] ?? null;
            if ($a && $a->opening_balance != 0) {
                $flows[] = [$a->opened_at?->format('Y-m-d') ?? ($this->transactions[0]->date ?? $this->today), -$a->opening_balance];
            }
        }
        foreach ($this->transactions as $t) {
            $delta = 0.0;
            foreach ($ids as $id) {
                $delta += self::txDelta($t, $id);
            }
            // Transferts internes au périmètre : neutres.
            if (abs($delta) > 0.0001) {
                $flows[] = [$t->date, -$delta];
            }
        }
        $final = 0.0;
        foreach ($ids as $id) {
            if (isset($this->accounts[$id])) {
                $final += $this->accountValue($this->accounts[$id]);
            }
        }
        if (! $flows || $final <= 0) {
            return null;
        }
        $flows[] = [$this->today, $final];
        $rate = Xirr::compute($flows);

        return $rate === null ? null : round($rate * 100, 2);
    }

    /** Synthèse complète du portefeuille d'investissement. */
    public function portfolio(): array
    {
        return $this->remember('portfolio', fn () => $this->computePortfolio());
    }

    private function computePortfolio(): array
    {
        $positions = $this->holdings();
        $accounts = [];
        $investIds = [];
        foreach ($this->accounts as $a) {
            if (! $a->isInvestment() || $a->is_archived) {
                continue;
            }
            if ($a->manual_value === null) {
                $investIds[] = $a->id;
            }
            $accounts[] = array_merge(
                ['id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'institution' => $a->institution,
                    'color' => $a->color, 'opened_at' => $a->opened_at?->format('Y-m-d')],
                $this->accountSummary($a)
            );
        }

        $value = array_sum(array_column($accounts, 'value'));
        $contrib = array_sum(array_column($accounts, 'contributions'));
        $cash = array_sum(array_column($accounts, 'balance'));
        $cost = array_sum(array_column($positions, 'cost'));
        $unrealized = array_sum(array_column($positions, 'unrealized'));

        $closed = array_filter($this->holdings(null, null, true), fn ($p) => $p['quantity'] <= 0);
        $realized = array_sum(array_column($this->holdings(null, null, true), 'realized'));
        $dividends = 0.0;
        $fees = 0.0;
        $income = [];
        $since = Carbon::today()->subMonths(11)->startOfMonth()->format('Y-m');
        foreach ($this->operations as $o) {
            if (in_array($o->type, ['dividend', 'interest'], true)) {
                $dividends += $o->amount;
                $m = substr($o->date, 0, 7);
                if ($m >= $since) {
                    $income[$m] = ($income[$m] ?? 0) + $o->amount;
                }
            }
            $fees += $o->type === 'fee' ? $o->amount : $o->fees;
        }

        $byClass = [];
        $byRegion = [];
        $bySector = [];
        foreach ($positions as &$p) {
            $p['weight'] = $value > 0 ? round($p['value'] / $value * 100, 2) : 0;
            $byClass[$p['asset_class']] = ($byClass[$p['asset_class']] ?? 0) + $p['value'];
            $byRegion[$p['region'] ?: 'Non renseigné'] = ($byRegion[$p['region'] ?: 'Non renseigné'] ?? 0) + $p['value'];
            $bySector[$p['sector'] ?: 'Non renseigné'] = ($bySector[$p['sector'] ?: 'Non renseigné'] ?? 0) + $p['value'];
        }
        unset($p);
        if ($cash > 0) {
            $byClass['cash'] = ($byClass['cash'] ?? 0) + $cash;
        }
        foreach ([&$byClass, &$byRegion, &$bySector] as &$arr) {
            arsort($arr);
            $arr = array_map(fn ($v) => round($v, 2), $arr);
        }
        unset($arr);

        $dividendMonths = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::today()->startOfMonth()->subMonths($i)->format('Y-m');
            $dividendMonths[] = ['month' => $m, 'amount' => round($income[$m] ?? 0, 2)];
        }

        return [
            'totals' => [
                'value' => round($value, 2),
                'cash' => round($cash, 2),
                'contributions' => round($contrib, 2),
                'gain' => round($value - $contrib, 2),
                'gain_pct' => $contrib > 0 ? round(($value - $contrib) / $contrib * 100, 2) : 0,
                'cost' => round($cost, 2),
                'unrealized' => round($unrealized, 2),
                'unrealized_pct' => $cost > 0 ? round($unrealized / $cost * 100, 2) : 0,
                'realized' => round($realized, 2),
                'dividends' => round($dividends, 2),
                'fees' => round($fees, 2),
                'xirr' => $investIds ? $this->xirr($investIds) : null,
            ],
            'accounts' => $accounts,
            'positions' => $positions,
            'closed_positions' => array_values($closed),
            'allocation' => ['by_class' => $byClass, 'by_region' => $byRegion, 'by_sector' => $bySector],
            'dividends_by_month' => $dividendMonths,
        ];
    }

    public function today(): string
    {
        return $this->today;
    }
}
