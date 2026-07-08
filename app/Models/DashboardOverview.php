<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardOverview extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'dashboard_overviews';

    protected $fillable = [
        'user_id',
        'weekly_progress',
        'quiz_trend',
        'subject_performance',
        'weak_topics',
        'strong_topics',
        'study_goals',
        'recent_activities',
        'recommendations',
    ];

    protected $casts = [
        'weekly_progress' => 'array',
        'quiz_trend' => 'array',
        'subject_performance' => 'array',
        'weak_topics' => 'array',
        'strong_topics' => 'array',
        'study_goals' => 'array',
        'recent_activities' => 'array',
        'recommendations' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
