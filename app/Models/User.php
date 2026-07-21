<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'full_name',
        'email',
        'password',
        'profile_picture',
        'google_id',
        'last_login_date',
        'role',
        'about_me',
        'primary_course',
        'language',
    ];

    public function documents()
    {
        return $this->hasMany(\App\Models\Document::class);
    }

    public function dashboardStatistic()
    {
        return $this->hasOne(\App\Models\DashboardStatistic::class);
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_date'   => 'datetime',
            'password'          => 'hashed',
        ];
    }
}
