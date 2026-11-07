<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'name', 'target_amount', 'current_amount',
        'target_date', 'color', 'priority',
    ];

    protected $casts = [
        'target_amount' => 'float',
        'current_amount' => 'float',
        'target_date' => 'date:Y-m-d',
        'priority' => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
