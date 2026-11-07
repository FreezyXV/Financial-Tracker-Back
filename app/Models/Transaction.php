<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'account_id', 'category_id', 'transfer_account_id', 'recurring_id',
        'type', 'amount', 'date', 'description', 'notes', 'tags', 'import_hash',
    ];

    protected $casts = [
        'amount' => 'float',
        'date' => 'date:Y-m-d',
        'tags' => 'array',
    ];

    protected $hidden = ['import_hash'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function transferAccount()
    {
        return $this->belongsTo(Account::class, 'transfer_account_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
