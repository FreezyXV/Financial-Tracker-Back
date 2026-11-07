<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    public const CLASSES = ['stock', 'etf', 'crypto', 'bond', 'fund', 'reit', 'commodity', 'other'];

    protected $fillable = [
        'user_id', 'symbol', 'name', 'asset_class', 'isin', 'currency', 'price_source',
        'source_id', 'current_price', 'fx_rate', 'price_updated_at', 'region', 'sector',
        'pea_eligible',
    ];

    protected $casts = [
        'current_price' => 'float',
        'fx_rate' => 'float',
        'price_updated_at' => 'datetime',
        'pea_eligible' => 'boolean',
    ];

    public function prices()
    {
        return $this->hasMany(AssetPrice::class);
    }

    public function operations()
    {
        return $this->hasMany(InvestmentOperation::class);
    }
}
