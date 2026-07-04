<?php

namespace App\Http\Controllers;

use App\Models\DashboardOverview;
use App\Models\DashboardStatistic;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics for current user
     */
    public function stats(Request $request)
    {
        $user = $request->user();

        $stats = DashboardStatistic::firstOrCreate(
            ['user_id' => $user->id],
            [
                'documents_uploaded' => 0,
                'summaries_generated' => 0,
                'quizzes_completed' => 0,
                'avg_quiz_score' => 0,
                'study_time_hours' => 0,
                'learning_streak' => 0,
            ]
        );

        $overview = DashboardOverview::firstOrCreate(
            ['user_id' => $user->id],
            [
                'weekly_progress' => [
                    ['name' => 'Mon', 'sessions' => 0],
                    ['name' => 'Tue', 'sessions' => 0],
                    ['name' => 'Wed', 'sessions' => 0],
                    ['name' => 'Thu', 'sessions' => 0],
                    ['name' => 'Fri', 'sessions' => 0],
                    ['name' => 'Sat', 'sessions' => 0],
                    ['name' => 'Sun', 'sessions' => 0],
                ],
                'quiz_trend' => [
                    ['month' => 'Jan', 'score' => 0],
                    ['month' => 'Feb', 'score' => 0],
                    ['month' => 'Mar', 'score' => 0],
                    ['month' => 'Apr', 'score' => 0],
                    ['month' => 'May', 'score' => 0],
                    ['month' => 'Jun', 'score' => 0],
                ],
                'subject_performance' => [
                    ['subject' => 'Mathematics', 'A' => 0, 'fullMark' => 100],
                    ['subject' => 'Physics', 'A' => 0, 'fullMark' => 100],
                    ['subject' => 'Chemistry', 'A' => 0, 'fullMark' => 100],
                    ['subject' => 'Biology', 'A' => 0, 'fullMark' => 100],
                    ['subject' => 'Economics', 'A' => 0, 'fullMark' => 100],
                    ['subject' => 'History', 'A' => 0, 'fullMark' => 100],
                ],
                'weak_topics' => [],
                'strong_topics' => [],
                'study_goals' => [],
                'recent_activities' => [],
                'recommendations' => [],
            ]
        );

        return response()->json([
            'stats' => [
                'documents_uploaded' => $stats->documents_uploaded,
                'summaries_generated' => $stats->summaries_generated,
                'quizzes_completed' => $stats->quizzes_completed,
                'avg_quiz_score' => $stats->avg_quiz_score,
                'study_time_hours' => $stats->study_time_hours,
                'learning_streak' => $stats->learning_streak,
            ],
            'overview' => [
                'weekly_progress' => $overview->weekly_progress,
                'quiz_trend' => $overview->quiz_trend,
                'subject_performance' => $overview->subject_performance,
                'weak_topics' => $overview->weak_topics,
                'strong_topics' => $overview->strong_topics,
                'study_goals' => $overview->study_goals,
                'recent_activities' => $overview->recent_activities,
                'recommendations' => $overview->recommendations,
            ],
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Update dashboard statistics for current user
     */
    public function updateStats(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'documents_uploaded' => 'sometimes|integer|min:0',
            'summaries_generated' => 'sometimes|integer|min:0',
            'quizzes_completed' => 'sometimes|integer|min:0',
            'avg_quiz_score' => 'sometimes|integer|min:0|max:100',
            'study_time_hours' => 'sometimes|numeric|min:0',
            'learning_streak' => 'sometimes|integer|min:0',
            'overview' => 'sometimes|array',
        ]);

        $stats = DashboardStatistic::firstOrCreate(['user_id' => $user->id]);
        $stats->update(array_diff_key($validated, ['overview' => true]));

        if (isset($validated['overview'])) {
            $overview = DashboardOverview::firstOrCreate(['user_id' => $user->id]);
            $overview->update([
                'weekly_progress' => $validated['overview']['weekly_progress'] ?? $overview->weekly_progress,
                'quiz_trend' => $validated['overview']['quiz_trend'] ?? $overview->quiz_trend,
                'subject_performance' => $validated['overview']['subject_performance'] ?? $overview->subject_performance,
                'weak_topics' => $validated['overview']['weak_topics'] ?? $overview->weak_topics,
                'strong_topics' => $validated['overview']['strong_topics'] ?? $overview->strong_topics,
                'study_goals' => $validated['overview']['study_goals'] ?? $overview->study_goals,
                'recent_activities' => $validated['overview']['recent_activities'] ?? $overview->recent_activities,
                'recommendations' => $validated['overview']['recommendations'] ?? $overview->recommendations,
            ]);
        }

        return response()->json([
            'message' => 'Statistics updated successfully',
            'stats' => $stats->only([
                'documents_uploaded',
                'summaries_generated',
                'quizzes_completed',
                'avg_quiz_score',
                'study_time_hours',
                'learning_streak',
            ]),
        ]);
    }
}
