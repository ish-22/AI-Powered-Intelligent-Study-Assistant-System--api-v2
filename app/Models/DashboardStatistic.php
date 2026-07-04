<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardStatistic extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'dashboard_statistics';

    protected $fillable = [
        'user_id',
        'documents_uploaded',
        'summaries_generated',
        'quizzes_completed',
        'avg_quiz_score',
        'study_time_hours',
        'learning_streak',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
