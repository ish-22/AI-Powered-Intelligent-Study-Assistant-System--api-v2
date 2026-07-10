<?php

namespace App\Http\Controllers;

use App\Models\DashboardStatistic;
use App\Models\Document;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $user      = $request->user();
        $documents = Document::where('user_id', $user->id)->orderBy('created_at', 'asc')->get();

        $dbStats = DashboardStatistic::firstOrCreate(['user_id' => $user->id]);

        // ── Real counts from documents ────────────────────────────────────────
        $totalDocs      = $documents->count();
        $withSummary    = $documents->filter(fn($d) => !empty($d->summary))->count();
        $withQuiz       = $documents->filter(fn($d) => !empty($d->quiz_data))->count();

        // avg_quiz_score: average of all quiz scores stored per document
        // Each quiz_data is an array of questions; we use the stored stat if set,
        // otherwise estimate 75 per quizzed document
        $avgScore = (int) $dbStats->avg_quiz_score;
        if ($avgScore === 0 && $withQuiz > 0) {
            $avgScore = 75; // sensible default until user actually takes quizzes
        }

        $studyHours   = max((float) $dbStats->study_time_hours,  round($totalDocs * 0.8, 1));
        $streak       = max((int)   $dbStats->learning_streak,   min(30, $totalDocs + $withSummary));
        $quizzesDone  = max($withQuiz, (int) $dbStats->quizzes_completed);

        // ── Subject performance (use ACTUAL document subjects) ────────────────
        // Build a score per subject: base 60, +15 if has summary, +25 if has quiz
        $subjectMap = [];
        foreach ($documents as $doc) {
            $subject = trim($doc->subject) ?: 'General';
            if (!isset($subjectMap[$subject])) {
                $subjectMap[$subject] = ['total' => 0, 'count' => 0];
            }
            $score = 60 + ($doc->summary ? 15 : 0) + ($doc->quiz_data ? 25 : 0);
            $subjectMap[$subject]['total'] += $score;
            $subjectMap[$subject]['count'] += 1;
        }

        // If no documents, show placeholder subjects at 0
        if (empty($subjectMap)) {
            $subjectMap = [
                'Mathematics' => ['total' => 0, 'count' => 1],
                'Physics'     => ['total' => 0, 'count' => 1],
                'Chemistry'   => ['total' => 0, 'count' => 1],
                'Biology'     => ['total' => 0, 'count' => 1],
            ];
        }

        $subjectPerformance = [];
        foreach ($subjectMap as $subject => $data) {
            $subjectPerformance[] = [
                'subject'  => $subject,
                'A'        => (int) round($data['total'] / $data['count']),
                'fullMark' => 100,
            ];
        }

        $weakTopics = collect($subjectPerformance)
            ->where('A', '<', 75)
            ->sortBy('A')
            ->take(3)
            ->map(fn($i) => ['name' => $i['subject'], 'score' => $i['A']])
            ->values()->all();

        $strongTopics = collect($subjectPerformance)
            ->where('A', '>=', 75)
            ->sortByDesc('A')
            ->take(3)
            ->map(fn($i) => ['name' => $i['subject'], 'score' => $i['A']])
            ->values()->all();

        // ── Weekly activity: count documents uploaded per day-of-week ─────────
        $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $dayCounts = array_fill(0, 7, 0);
        foreach ($documents as $doc) {
            $dow = (int) $doc->created_at->format('w'); // 0=Sun
            $dayCounts[$dow]++;
        }
        // Reorder Mon–Sun for display
        $weeklyProgress = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
            $weeklyProgress[] = ['name' => $dayLabels[$dow], 'sessions' => $dayCounts[$dow]];
        }

        // ── Quiz trend: documents created per month (last 6 months) ──────────
        $monthlyScores = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key   = $month->format('M');
            $docsThisMonth = $documents->filter(
                fn($d) => $d->created_at->format('Y-m') === $month->format('Y-m')
            );
            $hasQuiz = $docsThisMonth->filter(fn($d) => !empty($d->quiz_data))->count();
            // Score = avg_score if quizzes exist that month, else 0
            $monthlyScores[] = [
                'month' => $key,
                'score' => $hasQuiz > 0 ? $avgScore : 0,
            ];
        }

        // ── Recent activities from real documents ─────────────────────────────
        $recentActivities = $documents->sortByDesc('created_at')->take(5)->values()
            ->map(fn($d, $idx) => [
                'id'     => $idx + 1,
                'type'   => $d->quiz_data ? 'quiz' : ($d->summary ? 'summary' : 'document'),
                'title'  => $d->name,
                'status' => $d->quiz_data ? 'Quiz Ready' : ($d->summary ? 'Summarized' : 'Uploaded'),
                'score'  => $d->quiz_data ? "{$avgScore}%" : null,
                'time'   => $d->created_at->diffForHumans(),
            ])->all();

        if (empty($recentActivities)) {
            $recentActivities = [
                ['id' => 1, 'type' => 'info', 'title' => 'No activity yet', 'status' => 'Upload a document to get started', 'time' => 'Now'],
            ];
        }

        // ── Recommendations based on real data ────────────────────────────────
        $recommendations = [];
        if ($totalDocs === 0) {
            $recommendations[] = ['id' => 1, 'title' => 'Upload your first document', 'reason' => 'Start by uploading a study document to unlock all features.', 'urgency' => 'High'];
        }
        if ($totalDocs > 0 && $withSummary < $totalDocs) {
            $recommendations[] = ['id' => 2, 'title' => 'Generate summaries', 'reason' => ($totalDocs - $withSummary) . ' document(s) have no summary yet.', 'urgency' => 'Medium'];
        }
        if ($totalDocs > 0 && $withQuiz < $totalDocs) {
            $recommendations[] = ['id' => 3, 'title' => 'Generate quizzes', 'reason' => ($totalDocs - $withQuiz) . ' document(s) have no quiz yet.', 'urgency' => 'High'];
        }
        if (!empty($weakTopics)) {
            $recommendations[] = ['id' => 4, 'title' => "Improve {$weakTopics[0]['name']}", 'reason' => "Your score in {$weakTopics[0]['name']} is {$weakTopics[0]['score']}% — generate a focused quiz.", 'urgency' => 'High'];
        }
        if (empty($recommendations)) {
            $recommendations[] = ['id' => 1, 'title' => 'Keep it up!', 'reason' => 'You are doing great. Keep uploading and quizzing to maintain your streak.', 'urgency' => 'Low'];
        }

        return response()->json([
            'stats' => [
                'documents_uploaded'  => $totalDocs,
                'summaries_generated' => $withSummary,
                'quizzes_completed'   => $quizzesDone,
                'avg_quiz_score'      => $avgScore,
                'study_time_hours'    => $studyHours,
                'learning_streak'     => $streak,
            ],
            'overview' => [
                'weekly_progress'     => $weeklyProgress,
                'quiz_trend'          => $monthlyScores,
                'subject_performance' => $subjectPerformance,
                'weak_topics'         => $weakTopics,
                'strong_topics'       => $strongTopics,
                'study_goals' => [
                    ['id' => 1, 'title' => 'Review weak areas',       'progress' => max(10, min(100, $avgScore)),          'date' => now()->addDays(7)->format('Y-m-d')],
                    ['id' => 2, 'title' => 'Upload 5 documents',      'progress' => min(100, (int) ($totalDocs / 5 * 100)), 'date' => now()->addDays(14)->format('Y-m-d')],
                    ['id' => 3, 'title' => 'Complete all quizzes',    'progress' => $totalDocs > 0 ? min(100, (int) ($withQuiz / $totalDocs * 100)) : 0, 'date' => now()->addDays(21)->format('Y-m-d')],
                ],
                'recent_activities'   => $recentActivities,
                'recommendations'     => $recommendations,
            ],
            'user' => [
                'id'        => $user->id,
                'full_name' => $user->full_name,
                'email'     => $user->email,
            ],
        ]);
    }

    public function updateStats(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'quizzes_completed' => 'sometimes|integer|min:0',
            'avg_quiz_score'    => 'sometimes|integer|min:0|max:100',
            'study_time_hours'  => 'sometimes|numeric|min:0',
            'learning_streak'   => 'sometimes|integer|min:0',
        ]);

        $stats = DashboardStatistic::firstOrCreate(['user_id' => $user->id]);

        // For avg_quiz_score: compute rolling average if a new score is submitted
        if (isset($validated['avg_quiz_score']) && $stats->quizzes_completed > 0) {
            $currentTotal = $stats->avg_quiz_score * $stats->quizzes_completed;
            $newCount     = ($validated['quizzes_completed'] ?? $stats->quizzes_completed);
            $validated['avg_quiz_score'] = (int) round(($currentTotal + $validated['avg_quiz_score']) / max(1, $newCount + 1));
        }

        $stats->update($validated);

        return response()->json([
            'message' => 'Statistics updated successfully',
            'stats'   => $stats->only([
                'quizzes_completed', 'avg_quiz_score', 'study_time_hours', 'learning_streak',
            ]),
        ]);
    }
}
