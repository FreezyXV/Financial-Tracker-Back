<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Transaction;
use App\Models\User;

class AlertService
{
    public static function raise(User $user, string $type, string $level, string $message, ?string $trigger = null, ?string $dedupe = null): ?Alert
    {
        if ($dedupe && Alert::where('user_id', $user->id)->where('dedupe_key', $dedupe)->exists()) {
            return null;
        }

        return Alert::create([
            'user_id' => $user->id, 'type' => $type, 'level' => $level, 'message' => $message,
            'trigger' => $trigger, 'dedupe_key' => $dedupe,
        ]);
    }

    /** Contrôles après création / modification d'une transaction. */
    public static function afterTransaction(User $user, Transaction $tx): void
    {
        if ($tx->type !== 'expense') {
            return;
        }
        $threshold = (float) $user->large_transaction_threshold;
        if ($threshold > 0 && $tx->amount >= $threshold) {
            self::raise($user, 'transaction', 'info',
                sprintf('Dépense importante : %s € — %s.', number_format($tx->amount, 2, ',', ' '), $tx->description ?: 'sans libellé'),
                'large_transaction', 'tx:'.$tx->id);
        }
        if (! $tx->category_id) {
            return;
        }
        $month = $tx->date->format('Y-m');
        $report = new ReportService($user);
        foreach ($report->budgets($month) as $b) {
            $concerned = $b['category_id'] === $tx->category_id
                || optional($tx->category)->parent_id === $b['category_id'];
            if (! $concerned) {
                continue;
            }
            if ($b['status'] === 'over') {
                self::raise($user, 'budget', 'danger',
                    "Budget {$b['name']} dépassé : ".number_format($b['spent'], 0, ',', ' ').' € / '.number_format($b['limit'], 0, ',', ' ').' €.',
                    'limit_exceeded', "budget:{$b['id']}:{$month}:over");
            } elseif ($b['status'] === 'warning') {
                self::raise($user, 'budget', 'warning',
                    "Budget {$b['name']} consommé à {$b['percent']} %.",
                    'threshold_reached', "budget:{$b['id']}:{$month}:warn");
            }
        }
    }
}
