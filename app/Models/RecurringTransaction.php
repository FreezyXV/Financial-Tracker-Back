<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class RecurringTransaction extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'category_id', 'transfer_account_id', 'type', 'amount',
        'description', 'frequency', 'next_date', 'end_date', 'is_subscription',
        'is_active', 'auto_post',
    ];

    protected $casts = [
        'amount' => 'float',
        'next_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'is_subscription' => 'boolean',
        'is_active' => 'boolean',
        'auto_post' => 'boolean',
    ];

    /** Coût mensualisé (utile pour le total des abonnements). */
    public function monthlyAmount(): float
    {
        return match ($this->frequency) {
            'weekly' => $this->amount * 52 / 12,
            'quarterly' => $this->amount / 3,
            'yearly' => $this->amount / 12,
            default => $this->amount,
        };
    }

    public function advance(Carbon $date): Carbon
    {
        return match ($this->frequency) {
            'weekly' => $date->copy()->addWeek(),
            'quarterly' => $date->copy()->addMonthsNoOverflow(3),
            'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
