<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DashboardStatistic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RecommendationsController extends Controller
{
    /**
     * POST /recommendations/generate
     * Uses AI to generate personalized study recommendations from the user's real data.
     */
    public function generate(Request $request)
    {
        $user      = $request->user();
        $documents = Document::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
        $dbStats   = DashboardStatistic::firstOrCreate(['user_id' => $user->id]);

        $totalDocs   = $documents->count();
        $withSummary = $documents->filter(fn($d) => !empty($d->summary))->count();
        $withQuiz    = $documents->filter(fn($d) => !empty($d->quiz_data))->count();
        $avgScore    = (int) $dbStats->avg_quiz_score ?: ($withQuiz > 0 ? 75 : 0);
        $streak      = (int) $dbStats->learning_streak;

        // ── Build subject performance map ─────────────────────────────────────
        $subjectMap = [];
        foreach ($documents as $doc) {
            $subject = trim($doc->subject) ?: 'General';
            if (!isset($subjectMap[$subject])) {
                $subjectMap[$subject] = ['docs' => 0, 'quizzed' => 0, 'summarized' => 0, 'names' => []];
            }
            $subjectMap[$subject]['docs']++;
            if (!empty($doc->quiz_data))  $subjectMap[$subject]['quizzed']++;
            if (!empty($doc->summary))    $subjectMap[$subject]['summarized']++;
            $subjectMap[$subject]['names'][] = $doc->name;
        }

        // ── Documents needing action ──────────────────────────────────────────
        $needsQuiz    = $documents->filter(fn($d) => empty($d->quiz_data))->values();
        $needsSummary = $documents->filter(fn($d) => empty($d->summary))->values();

        // ── If no documents, return helpful empty state ───────────────────────
        if ($totalDocs === 0) {
            return response()->json([
                'recommendations' => [],
                'study_plan'      => [],
                'focus_tip'       => 'Upload your first document to unlock AI-powered recommendations.',
                'ai_insight'      => 'No data yet. Start by uploading a study document!',
                'summary'         => [
                    'total_docs'   => 0,
                    'with_quiz'    => 0,
                    'with_summary' => 0,
                    'avg_score'    => 0,
                    'streak'       => 0,
                ],
            ]);
        }

        // ── Build context string for AI ───────────────────────────────────────
        $subjectLines = [];
        foreach ($subjectMap as $subject => $data) {
            $score = 60 + ($data['quizzed'] > 0 ? 25 : 0) + ($data['summarized'] > 0 ? 15 : 0);
            $subjectLines[] = "- {$subject}: {$data['docs']} doc(s), score ~{$score}%, quizzed: {$data['quizzed']}, summarized: {$data['summarized']}";
        }
        $subjectContext = implode("\n", $subjectLines);

        $needsQuizNames    = $needsQuiz->take(5)->pluck('name')->implode(', ');
        $needsSummaryNames = $needsSummary->take(5)->pluck('name')->implode(', ');

        $prompt = "You are an expert AI study coach. A student has the following academic profile:\n\n"
            . "Total documents: {$totalDocs}\n"
            . "Documents with quiz: {$withQuiz}\n"
            . "Documents with summary: {$withSummary}\n"
            . "Average quiz score: {$avgScore}%\n"
            . "Learning streak: {$streak} days\n\n"
            . "Subject performance:\n{$subjectContext}\n\n"
            . ($needsQuizNames    ? "Documents needing quiz: {$needsQuizNames}\n"    : '')
            . ($needsSummaryNames ? "Documents needing summary: {$needsSummaryNames}\n" : '')
            . "\nGenerate a JSON response with EXACTLY this structure (no extra text):\n"
            . "{\n"
            . "  \"recommendations\": [\n"
            . "    {\"id\":1,\"topic\":\"...\",\"priority\":\"High|Medium|Low\",\"confidence\":0-100,\"estimated_minutes\":10-90,\"reason\":\"...\",\"action\":\"quiz|summary|review\",\"subject\":\"...\"}\n"
            . "  ],\n"
            . "  \"study_plan\": [\n"
            . "    {\"time\":\"09:00 AM\",\"task\":\"...\",\"type\":\"quiz|summary|review|break\",\"duration_mins\":30}\n"
            . "  ],\n"
            . "  \"focus_tip\": \"One actionable tip based on the student's data\",\n"
            . "  \"ai_insight\": \"2-3 sentence personalized insight about their learning pattern\"\n"
            . "}\n\n"
            . "Rules:\n"
            . "- Generate 3-5 recommendations ordered by priority\n"
            . "- Study plan should have 4-6 time slots for today\n"
            . "- Base everything on the REAL data provided, not generic advice\n"
            . "- confidence = estimated mastery % for that topic\n"
            . "- If avg_score is 0, assume student is just starting out\n"
            . "- Respond ONLY with valid JSON, no markdown, no explanation";

        $raw = $this->callAI([
            ['role' => 'system', 'content' => 'You are a precise AI study coach. Always respond with valid JSON only.'],
            ['role' => 'user',   'content' => $prompt],
        ], 1200);

        // ── Parse AI response ─────────────────────────────────────────────────
        $aiData = null;
        if (!str_contains($raw, 'AI is not configured') && !str_contains($raw, 'AI request failed')) {
            // Strip markdown code fences if present
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            $aiData  = json_decode($cleaned, true);
        }

        // ── Fallback: build from real data if AI fails ────────────────────────
        if (!$aiData || !isset($aiData['recommendations'])) {
            $aiData = $this->buildFallback($subjectMap, $needsQuiz, $needsSummary, $avgScore, $streak);
        }

        // ── Attach real document IDs to recommendations ───────────────────────
        $recs = collect($aiData['recommendations'] ?? [])->map(function ($rec, $idx) use ($documents) {
            $subject = $rec['subject'] ?? '';
            $action  = $rec['action']  ?? 'review';

            // Find a matching document for this recommendation
            $matchDoc = $documents->first(function ($d) use ($subject, $action) {
                $subjectMatch = !$subject || stripos($d->subject ?? '', $subject) !== false;
                if ($action === 'quiz')    return $subjectMatch && empty($d->quiz_data);
                if ($action === 'summary') return $subjectMatch && empty($d->summary);
                return $subjectMatch;
            }) ?? $documents->first();

            return array_merge($rec, [
                'id'          => $idx + 1,
                'document_id' => $matchDoc?->id,
                'document_name' => $matchDoc?->name,
            ]);
        })->values()->all();

        return response()->json([
            'recommendations' => $recs,
            'study_plan'      => $aiData['study_plan']      ?? [],
            'focus_tip'       => $aiData['focus_tip']       ?? 'Stay consistent — even 30 minutes a day compounds over time.',
            'ai_insight'      => $aiData['ai_insight']      ?? 'Keep uploading documents and taking quizzes to unlock deeper insights.',
            'summary' => [
                'total_docs'   => $totalDocs,
                'with_quiz'    => $withQuiz,
                'with_summary' => $withSummary,
                'avg_score'    => $avgScore,
                'streak'       => $streak,
            ],
        ]);
    }

    // ── Fallback builder ──────────────────────────────────────────────────────

    private function buildFallback($subjectMap, $needsQuiz, $needsSummary, int $avgScore, int $streak): array
    {
        $recs = [];
        $id   = 1;

        foreach ($subjectMap as $subject => $data) {
            $score = 60 + ($data['quizzed'] > 0 ? 25 : 0) + ($data['summarized'] > 0 ? 15 : 0);
            if ($data['quizzed'] === 0) {
                $recs[] = ['id' => $id++, 'topic' => $subject, 'priority' => 'High', 'confidence' => $score,
                    'estimated_minutes' => 30, 'reason' => "No quiz generated yet for {$subject}.", 'action' => 'quiz', 'subject' => $subject];
            } elseif ($data['summarized'] === 0) {
                $recs[] = ['id' => $id++, 'topic' => $subject, 'priority' => 'Medium', 'confidence' => $score,
                    'estimated_minutes' => 20, 'reason' => "No summary generated yet for {$subject}.", 'action' => 'summary', 'subject' => $subject];
            } else {
                $recs[] = ['id' => $id++, 'topic' => $subject, 'priority' => 'Low', 'confidence' => $score,
                    'estimated_minutes' => 15, 'reason' => "Review and reinforce your {$subject} knowledge.", 'action' => 'review', 'subject' => $subject];
            }
            if ($id > 5) break;
        }

        $plan = [
            ['time' => '09:00 AM', 'task' => 'Review your weakest subject', 'type' => 'review', 'duration_mins' => 30],
            ['time' => '10:00 AM', 'task' => 'Generate a quiz on a new document', 'type' => 'quiz', 'duration_mins' => 20],
            ['time' => '02:00 PM', 'task' => 'Generate summaries for unread docs', 'type' => 'summary', 'duration_mins' => 25],
            ['time' => '04:00 PM', 'task' => 'AI Chat — ask questions about weak topics', 'type' => 'review', 'duration_mins' => 20],
        ];

        return [
            'recommendations' => $recs,
            'study_plan'      => $plan,
            'focus_tip'       => $avgScore < 70
                ? 'Your quiz scores suggest you need more active recall. Generate quizzes on every document.'
                : 'Great scores! Keep the streak going by studying at least 30 minutes daily.',
            'ai_insight'      => $streak > 3
                ? "You have a {$streak}-day streak — excellent consistency! Focus on generating quizzes for subjects you haven't tested yet."
                : 'Build a daily habit. Even short sessions dramatically improve retention over time.',
        ];
    }

    private function callAI(array $messages, int $maxTokens = 1200): string
    {
        $apiKey = config('services.openrouter.key');
        if (!$apiKey) return 'AI is not configured.';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => 'Study Assistant',
        ])->timeout(60)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model'      => 'openai/gpt-3.5-turbo',
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ]);

        if ($response->failed()) return 'AI request failed.';

        return $response->json('choices.0.message.content') ?? 'Unexpected AI response.';
    }
}
