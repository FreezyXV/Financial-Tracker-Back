<?php

namespace App\Services;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringService
{
    /** Passe en écriture les échéances arrivées à terme. Retourne le nombre créé. */
    public static function postDue(User $user): int
    {
        $created = 0;
        $today = Carbon::today();
        $items = RecurringTransaction::where('user_id', $user->id)->where('is_active', true)
            ->where('auto_post', true)->whereDate('next_date', '<=', $today)->get();
        foreach ($items as $r) {
            DB::transaction(function () use ($r, $today, $user, &$created) {
                $date = $r->next_date->copy();
                $guard = 0;
                while ($date->lte($today) && (! $r->end_date || $date->lte($r->end_date)) && $guard++ < 120) {
                    Transaction::create([
                        'user_id' => $user->id, 'account_id' => $r->account_id,
                        'category_id' => $r->category_id, 'transfer_account_id' => $r->transfer_account_id,
                        'recurring_id' => $r->id, 'type' => $r->type, 'amount' => $r->amount,
                        'date' => $date->format('Y-m-d'), 'description' => $r->description,
                    ]);
                    $created++;
                    $date = $r->advance($date);
                }
                $r->next_date = $date;
                if ($r->end_date && $date->gt($r->end_date)) {
                    $r->is_active = false;
                }
                $r->save();
            });
        }

        return $created;
    }

    /** Échéances à venir sur N jours (projection, sans écriture). */
    public static function upcoming(User $user, int $days = 30): array
    {
        $limit = Carbon::today()->addDays($days);
        $out = [];
        $items = RecurringTransaction::where('user_id', $user->id)->where('is_active', true)
            ->with('category:id,name,color,icon')->get();
        foreach ($items as $r) {
            $date = $r->next_date->copy();
            $guard = 0;
            while ($date->lte($limit) && (! $r->end_date || $date->lte($r->end_date)) && $guard++ < 60) {
                $out[] = [
                    'recurring_id' => $r->id, 'date' => $date->format('Y-m-d'),
                    'description' => $r->description, 'amount' => $r->amount, 'type' => $r->type,
                    'category' => $r->category, 'is_subscription' => $r->is_subscription,
                ];
                $date = $r->advance($date);
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    /** Détection d'abonnements / prélèvements récurrents non encore déclarés. */
    public static function suggestions(User $user): array
    {
        $since = Carbon::today()->subMonths(7)->format('Y-m-d');
        $rows = DB::table('transactions')->where('user_id', $user->id)
            ->where('type', 'expense')->whereNull('recurring_id')
            ->where('date', '>=', $since)->orderBy('date')
            ->get(['description', 'amount', 'date', 'category_id', 'account_id']);
        $known = RecurringTransaction::where('user_id', $user->id)->pluck('description')
            ->map(fn ($d) => Categorizer::normalize($d))->all();

        $groups = [];
        foreach ($rows as $r) {
            $key = Categorizer::normalize($r->description);
            if ($key === '' || in_array($key, $known, true)) {
                continue;
            }
            $groups[$key][] = $r;
        }
        $out = [];
        foreach ($groups as $key => $list) {
            $months = array_unique(array_map(fn ($r) => substr($r->date, 0, 7), $list));
            if (count($months) < 3 || count($list) > count($months) + 1) {
                continue;
            }
            $amounts = array_map(fn ($r) => (float) $r->amount, $list);
            $avg = array_sum($amounts) / count($amounts);
            if ($avg <= 0 || (max($amounts) - min($amounts)) > max(2, $avg * 0.15)) {
                continue;
            }
            $last = end($list);
            $out[] = [
                'description' => $last->description,
                'amount' => round($avg, 2),
                'occurrences' => count($list),
                'frequency' => 'monthly',
                'last_date' => substr($last->date, 0, 10),
                'next_date' => Carbon::parse($last->date)->addMonthNoOverflow()->format('Y-m-d'),
                'category_id' => $last->category_id,
                'account_id' => $last->account_id,
                'yearly_cost' => round($avg * 12, 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['yearly_cost'] <=> $a['yearly_cost']);

        return $out;
    }
}
