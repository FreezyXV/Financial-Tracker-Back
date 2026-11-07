<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'type', 'level', 'message', 'trigger', 'dedupe_key', 'is_read'];

    protected $casts = ['is_read' => 'boolean'];

    protected $hidden = ['dedupe_key'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
