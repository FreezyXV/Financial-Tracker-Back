<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetPrice extends Model
{
    public $timestamps = false;

    protected $fillable = ['asset_id', 'date', 'price', 'fx_rate'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'price' => 'float',
        'fx_rate' => 'float',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
