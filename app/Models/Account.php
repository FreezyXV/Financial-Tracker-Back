<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    /** Types d'enveloppes d'investissement (valorisées avec positions). */
    public const INVESTMENT_TYPES = ['pea', 'pea_pme', 'cto', 'assurance_vie', 'per', 'crypto'];

    /** Types valorisés manuellement (immobilier, etc.). */
    public const MANUAL_TYPES = ['real_estate'];

    public const TYPES = [
        'checking', 'savings', 'livret_a', 'ldds', 'lep', 'pel', 'pea', 'pea_pme',
        'cto', 'assurance_vie', 'per', 'crypto', 'cash', 'real_estate', 'loan', 'other',
    ];

    protected $fillable = [
        'user_id', 'name', 'type', 'institution', 'currency', 'opening_balance',
        'opened_at', 'manual_value', 'interest_rate', 'color',
        'include_in_net_worth', 'is_archived',
    ];

    protected $casts = [
        'opening_balance' => 'float',
        'manual_value' => 'float',
        'interest_rate' => 'float',
        'opened_at' => 'date:Y-m-d',
        'include_in_net_worth' => 'boolean',
        'is_archived' => 'boolean',
    ];

    public function isInvestment(): bool
    {
        return in_array($this->type, self::INVESTMENT_TYPES, true);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function operations()
    {
        return $this->hasMany(InvestmentOperation::class);
    }
}
