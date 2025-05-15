<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name'];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }
}

