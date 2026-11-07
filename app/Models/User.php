<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'currency',
        'savings_rate_target',
        'large_transaction_threshold',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'savings_rate_target' => 'float',
            'large_transaction_threshold' => 'float',
        ];
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function operations()
    {
        return $this->hasMany(InvestmentOperation::class);
    }

    public function recurring()
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function goals()
    {
        return $this->hasMany(Goal::class);
    }

    public function rules()
    {
        return $this->hasMany(CategorizationRule::class);
    }

    public function alerts()
    {
        return $this->hasMany(Alert::class);
    }
}
