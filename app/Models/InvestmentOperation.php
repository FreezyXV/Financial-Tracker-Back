<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'account_id', 'asset_id', 'type', 'quantity', 'price', 'fx_rate',
        'fees', 'amount', 'date', 'notes',
    ];

    protected $casts = [
        'quantity' => 'float',
        'price' => 'float',
        'fx_rate' => 'float',
        'fees' => 'float',
        'amount' => 'float',
        'date' => 'date:Y-m-d',
    ];

    /** Impact en EUR sur le cash de l'enveloppe. */
    public function cashImpact(): float
    {
        return match ($this->type) {
            'buy' => -($this->quantity * $this->price * $this->fx_rate + $this->fees),
            'sell' => $this->quantity * $this->price * $this->fx_rate - $this->fees,
            'dividend', 'interest' => $this->amount,
            'fee' => -$this->amount,
            default => 0.0,
        };
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
