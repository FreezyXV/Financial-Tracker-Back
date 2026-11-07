<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategorizationRule extends Model
{
    protected $fillable = ['user_id', 'category_id', 'pattern', 'priority'];

    protected $casts = ['priority' => 'integer'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
