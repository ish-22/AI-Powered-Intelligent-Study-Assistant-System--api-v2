<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class QuizController extends Controller
{
    // ==========================================
    // TEACHER ENDPOINTS
    // ==========================================

    /**
     * GET /api/quizzes
     * List quizzes.
     * Teacher: Sees quizzes they created with attempt counts & status.
     * Student: Sees quizzes published by their assigned teacher.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'teacher') {
            $quizzes = Quiz::with(['document', 'questions', 'attempts.user'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn($q) => $this->formatQuizForTeacher($q));

            return response()->json(['quizzes' => $quizzes]);
        }

        // Student role
        if (!$user->assigned_teacher_id) {
            return response()->json(['quizzes' => []]);
        }

        $now = now();
        $quizzes = Quiz::with(['document', 'questions'])
            ->where('user_id', $user->assigned_teacher_id)
            ->whereIn('status', ['PUBLISHED', 'CLOSED', 'GRADING', 'GRADED'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($q) => $this->formatQuizForStudent($q, $user));

        return response()->json(['quizzes' => $quizzes]);
    }

    /**
     * POST /api/quizzes/generate
     * Generate quiz using AI based on one or multiple documents.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'teacher') {
            return response()->json(['error' => 'Only teachers can generate quizzes'], 403);
        }

        $request->validate([
            'document_ids'   => 'required|array|min:1',
            'document_ids.*' => 'string',
            'title'          => 'nullable|string|max:255',
            'description'    => 'nullable|string',
            'count'          => 'integer|min:1|max:50',
            'difficulty'     => 'string|in:easy,medium,hard',
            'question_type'  => 'string|in:mcq,tf,short,mixed',
            'time_limit_mins'=> 'integer|min:5|max:180',
        ]);

        $docIds = $request->input('document_ids');
        $docs = Document::whereIn('id', $docIds)
            ->where('user_id', $user->id)
            ->get();

        if ($docs->isEmpty()) {
            return response()->json(['error' => 'No valid class materials selected'], 422);
        }

        $count          = $request->input('count', 5);
        $difficulty     = $request->input('difficulty', 'medium');
        $qType          = $request->input('question_type', 'mixed');
        $title          = $request->input('title') ?: 'Quiz: ' . $docs->first()->name;
        $description    = $request->input('description', 'AI generated assessment based on class material.');
        $timeLimit      = $request->input('time_limit_mins', 15);

        // Concatenate extract text
        $combinedText = '';
        foreach ($docs as $d) {
            $combinedText .= "Material: {$d->name} (Subject: {$d->subject})\n";
            $combinedText .= $this->extractText($d) . "\n\n";
        }
        $truncated = mb_substr($combinedText, 0, 6000);

        $promptType = match($qType) {
            'mcq'   => 'All questions must be Multiple Choice (type: mcq) with 4 options.',
            'tf'    => 'All questions must be True/False (type: tf) with 2 options ["A) True", "B) False"].',
            'short' => 'All questions must be Short Answer (type: short) with options null and a descriptive expected sample correct answer.',
            default => 'Include a mix of Multiple Choice (mcq), True/False (tf), and Short Answer (short) questions.',
        };

        $rawJson = $this->callAI([
            [
                'role'    => 'system',
                'content' => "You are an expert academic assessment creator. Generate exactly {$count} quiz questions based on the provided material at {$difficulty} difficulty level. {$promptType} Format JSON output strictly as: [{\"type\": \"mcq|tf|short\", \"question_text\": \"...\", \"options\": [\"A) ...\", \"B) ...\"], \"correct_answer\": \"...\", \"explanation\": \"...\", \"marks\": 10}]",
            ],
            [
                'role'    => 'user',
                'content' => "Generate {$count} {$difficulty} {$qType} questions from this material:\n\n{$truncated}",
            ],
        ]);

        $parsedQuestions = null;
        preg_match('/\[.*\]/s', $rawJson, $matches);
        if (!empty($matches[0])) {
            $parsedQuestions = json_decode($matches[0], true);
        }

        if (!is_array($parsedQuestions) || empty($parsedQuestions)) {
            $parsedQuestions = $this->generateFallbackQuestions($docs->first(), $count, $qType);
        }

        // Create Quiz in DRAFT status
        $quiz = Quiz::create([
            'user_id'         => $user->id,
            'document_id'     => $docs->first()->id,
            'material_ids'    => $docIds,
            'title'           => $title,
            'description'     => $description,
            'difficulty'      => $difficulty,
            'time_limit_mins' => $timeLimit,
            'status'          => 'DRAFT',
            'total_marks'     => count($parsedQuestions) * 10,
        ]);

        foreach ($parsedQuestions as $q) {
            QuizQuestion::create([
                'quiz_id'        => $quiz->id,
                'type'           => $q['type'] ?? 'mcq',
                'question_text'  => $q['question_text'] ?? 'Question text missing',
                'options'        => $q['options'] ?? null,
                'correct_answer' => $q['correct_answer'] ?? 'A) True',
                'explanation'    => $q['explanation'] ?? '',
                'marks'          => $q['marks'] ?? 10,
            ]);
        }

        return response()->json([
            'message' => 'Quiz drafted successfully',
            'quiz'    => $this->formatQuizForTeacher($quiz->fresh(['questions', 'document'])),
        ], 201);
    }

    /**
     * PUT /api/quizzes/{id}
     * Update quiz settings, questions, or schedule.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $quiz = Quiz::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $request->validate([
            'title'                    => 'sometimes|string|max:255',
            'description'              => 'nullable|string',
            'difficulty'               => 'sometimes|string',
            'time_limit_mins'          => 'sometimes|integer|min:1',
            'status'                   => 'sometimes|string|in:DRAFT,PUBLISHED,CLOSED,GRADING,GRADED',
            'start_at'                 => 'nullable|date',
            'deadline_at'              => 'nullable|date|after_or_equal:start_at',
            'total_marks'              => 'sometimes|integer',
            'show_results_immediately' => 'sometimes|boolean',
            'questions'                => 'nullable|array',
        ]);

        $quiz->update($request->only([
            'title', 'description', 'difficulty', 'time_limit_mins', 'status',
            'start_at', 'deadline_at', 'total_marks', 'show_results_immediately'
        ]));

        if ($request->has('questions')) {
            // Re-sync questions
            $quiz->questions()->delete();
            foreach ($request->input('questions') as $q) {
                QuizQuestion::create([
                    'quiz_id'        => $quiz->id,
                    'type'           => $q['type'] ?? 'mcq',
                    'question_text'  => $q['question_text'],
                    'options'        => $q['options'] ?? null,
                    'correct_answer' => $q['correct_answer'],
                    'explanation'    => $q['explanation'] ?? '',
                    'marks'          => $q['marks'] ?? 10,
                ]);
            }
        }

        return response()->json([
            'message' => 'Quiz updated',
            'quiz'    => $this->formatQuizForTeacher($quiz->fresh(['questions', 'document'])),
        ]);
    }

    /**
     * DELETE /api/quizzes/{id}
     */
    public function destroy(Request $request, string $id)
    {
        $quiz = Quiz::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $quiz->delete();
        return response()->json(['message' => 'Quiz deleted']);
    }

    /**
     * GET /api/quizzes/{id}/submissions
     * List all student submissions for a quiz.
     */
    public function submissions(Request $request, string $id)
    {
        $user = $request->user();
        $quiz = Quiz::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $attempts = QuizAttempt::with(['user', 'answers.question'])
            ->where('quiz_id', $quiz->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($a) => $this->formatAttemptForTeacher($a));

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'total_marks' => $quiz->total_marks,
                'status' => $quiz->status,
            ],
            'submissions' => $attempts,
        ]);
    }

    /**
     * POST /api/quizzes/attempts/{attemptId}/grade
     * Manually/finalize grade a student attempt.
     */
    public function gradeAttempt(Request $request, string $attemptId)
    {
        $user = $request->user();
        if ($user->role !== 'teacher') {
            return response()->json(['error' => 'Only teachers can grade attempts'], 403);
        }

        $attempt = QuizAttempt::with(['quiz', 'answers.question'])->findOrFail($attemptId);
        if ($attempt->quiz->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'teacher_feedback' => 'nullable|string',
            'answers'          => 'required|array',
            'answers.*.id'     => 'required|string',
            'answers.*.score'  => 'required|integer|min:0',
            'answers.*.is_correct' => 'required|boolean',
            'answers.*.teacher_feedback' => 'nullable|string',
            'finalize'         => 'nullable|boolean',
        ]);

        $totalEarned = 0;
        foreach ($request->input('answers') as $ansData) {
            $ans = QuizAttemptAnswer::where('id', $ansData['id'])
                ->where('quiz_attempt_id', $attempt->id)
                ->first();

            if ($ans) {
                $ans->update([
                    'score_awarded'      => $ansData['score'],
                    'is_correct'         => $ansData['is_correct'],
                    'teacher_feedback'   => $ansData['teacher_feedback'] ?? null,
                    'is_manually_graded' => true,
                ]);
                $totalEarned += $ansData['score'];
            }
        }

        $totalPossible = $attempt->quiz->total_marks ?: 100;
        $pct = $totalPossible > 0 ? (int) round(($totalEarned / $totalPossible) * 100) : 0;
        $grade = $this->calculateGradeLetter($pct);

        $status = $request->input('finalize') ? 'GRADED' : 'UNDER_REVIEW';

        $attempt->update([
            'total_marks_obtained' => $totalEarned,
            'score_percentage'     => $pct,
            'grade'                => $grade,
            'teacher_feedback'     => $request->input('teacher_feedback'),
            'status'               => $status,
        ]);

        return response()->json([
            'message' => 'Attempt graded successfully',
            'attempt' => $this->formatAttemptForTeacher($attempt->fresh(['user', 'answers.question'])),
        ]);
    }

    // ==========================================
    // STUDENT ENDPOINTS
    // ==========================================

    /**
     * POST /api/quizzes/{id}/start
     * Start a quiz attempt (enforces scheduling & time limits).
     */
    public function start(Request $request, string $id)
    {
        $user = $request->user();
        $quiz = Quiz::with('questions')->findOrFail($id);

        // Scheduling checks
        $now = now();
        if ($quiz->start_at && $now->lt($quiz->start_at)) {
            return response()->json(['error' => 'Quiz has not opened yet. Opens at: ' . $quiz->start_at->toDateTimeString()], 400);
        }

        if ($quiz->deadline_at && $now->gt($quiz->deadline_at)) {
            return response()->json(['error' => 'Quiz deadline has passed.'], 400);
        }

        if ($quiz->status === 'DRAFT' || $quiz->status === 'CLOSED') {
            return response()->json(['error' => 'Quiz is currently unavailable.'], 400);
        }

        // Check if student already submitted/graded
        $existing = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && in_array($existing->status, ['SUBMITTED', 'UNDER_REVIEW', 'GRADED'])) {
            return response()->json(['error' => 'You have already submitted this quiz.'], 400);
        }

        if (!$existing) {
            $existing = QuizAttempt::create([
                'quiz_id'     => $quiz->id,
                'user_id'     => $user->id,
                'status'      => 'IN_PROGRESS',
                'started_at'  => $now,
            ]);
        }

        // Mask correct answers from student payload
        $questions = $quiz->questions->map(function ($q) {
            return [
                'id'            => $q->id,
                'type'          => $q->type,
                'question_text' => $q->question_text,
                'options'       => $q->options,
                'marks'         => $q->marks,
            ];
        });

        return response()->json([
            'attempt_id'      => $existing->id,
            'quiz'            => [
                'id'              => $quiz->id,
                'title'           => $quiz->title,
                'description'     => $quiz->description,
                'time_limit_mins' => $quiz->time_limit_mins,
                'total_marks'     => $quiz->total_marks,
            ],
            'started_at'      => $existing->started_at->toIso8601String(),
            'questions'       => $questions,
        ]);
    }

    /**
     * POST /api/quizzes/attempts/{attemptId}/submit
     * Submit answers for a quiz attempt & perform auto-grading on MCQs.
     */
    public function submit(Request $request, string $attemptId)
    {
        $user = $request->user();
        $attempt = QuizAttempt::with(['quiz.questions'])->findOrFail($attemptId);

        if ($attempt->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($attempt->status !== 'IN_PROGRESS') {
            return response()->json(['error' => 'Attempt already submitted'], 400);
        }

        $request->validate([
            'answers'                   => 'required|array',
            'answers.*.quiz_question_id'=> 'required|string',
            'answers.*.student_answer'  => 'nullable|string',
            'time_spent_seconds'        => 'integer|min:0',
        ]);

        $questionsMap = $attempt->quiz->questions->keyBy('id');
        $totalEarned = 0;
        $hasSubjective = false;

        foreach ($request->input('answers') as $ans) {
            $qId   = $ans['quiz_question_id'];
            $stAns = trim($ans['student_answer'] ?? '');
            $q     = $questionsMap->get($qId);

            if (!$q) continue;

            $isCorrect = false;
            $score     = 0;
            $aiFeedback= null;

            if ($q->type === 'mcq' || $q->type === 'tf') {
                // Auto-grade objective questions
                $corrNorm = strtolower(trim(preg_replace('/^[A-D]\)\s*/i', '', $q->correct_answer)));
                $ansNorm  = strtolower(trim(preg_replace('/^[A-D]\)\s*/i', '', $stAns)));

                if ($corrNorm === $ansNorm || strcasecmp($q->correct_answer, $stAns) === 0) {
                    $isCorrect = true;
                    $score     = $q->marks;
                    $aiFeedback= 'Correct answer!';
                } else {
                    $aiFeedback= 'Incorrect.';
                }
            } else {
                // Short answer / subjective -> flagged for teacher review
                $hasSubjective = true;
                $aiFeedback    = 'Pending teacher evaluation.';
            }

            $totalEarned += $score;

            QuizAttemptAnswer::create([
                'quiz_attempt_id'  => $attempt->id,
                'quiz_question_id' => $qId,
                'student_answer'   => $stAns,
                'is_correct'       => $isCorrect,
                'score_awarded'    => $score,
                'ai_feedback'      => $aiFeedback,
            ]);
        }

        $totalPossible = $attempt->quiz->total_marks ?: 100;
        $pct = $totalPossible > 0 ? (int) round(($totalEarned / $totalPossible) * 100) : 0;
        $grade = $this->calculateGradeLetter($pct);

        $nextStatus = ($hasSubjective || !$attempt->quiz->show_results_immediately)
            ? 'UNDER_REVIEW'
            : 'GRADED';

        $attempt->update([
            'status'               => $nextStatus,
            'completed_at'         => now(),
            'time_spent_seconds'   => $request->input('time_spent_seconds', 0),
            'total_marks_obtained' => $totalEarned,
            'score_percentage'     => $pct,
            'grade'                => $grade,
        ]);

        return response()->json([
            'message' => 'Quiz submitted successfully!',
            'status'  => $nextStatus,
            'attempt' => $this->formatAttemptForStudent($attempt->fresh(['quiz', 'answers.question']), $attempt->quiz),
        ]);
    }

    /**
     * GET /api/quizzes/attempts/{attemptId}/result
     * View attempt result for student.
     */
    public function result(Request $request, string $attemptId)
    {
        $user = $request->user();
        $attempt = QuizAttempt::with(['quiz.questions', 'answers.question'])->findOrFail($attemptId);

        if ($attempt->user_id !== $user->id && $user->role !== 'teacher') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'result' => $this->formatAttemptForStudent($attempt, $attempt->quiz),
        ]);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function formatQuizForTeacher(Quiz $q): array
    {
        return [
            'id'                       => $q->id,
            'title'                    => $q->title,
            'description'              => $q->description,
            'difficulty'               => ucfirst($q->difficulty),
            'time_limit_mins'          => $q->time_limit_mins,
            'status'                   => $q->status,
            'start_at'                 => $q->start_at ? $q->start_at->toIso8601String() : null,
            'deadline_at'              => $q->deadline_at ? $q->deadline_at->toIso8601String() : null,
            'total_marks'              => $q->total_marks,
            'show_results_immediately' => $q->show_results_immediately,
            'document_id'              => $q->document_id,
            'document_name'            => $q->document ? $q->document->name : 'Class Material',
            'questions'                => $q->questions->map(fn($item) => [
                'id'             => $item->id,
                'type'           => $item->type,
                'question_text'  => $item->question_text,
                'options'        => $item->options,
                'correct_answer' => $item->correct_answer,
                'explanation'    => $item->explanation,
                'marks'          => $item->marks,
            ]),
            'submissions_count'        => $q->attempts->count(),
            'avg_score'                => round($q->attempts->avg('score_percentage') ?? 0, 1),
            'created_at'               => $q->created_at->toDateTimeString(),
        ];
    }

    private function formatQuizForStudent(Quiz $q, User $student): array
    {
        $attempt = QuizAttempt::where('quiz_id', $q->id)
            ->where('user_id', $student->id)
            ->first();

        $status = 'Upcoming';
        $now = now();

        if ($q->start_at && $now->lt($q->start_at)) {
            $status = 'Upcoming';
        } elseif ($q->deadline_at && $now->gt($q->deadline_at)) {
            $status = 'Closed';
        } else {
            $status = 'Available';
        }

        if ($attempt) {
            $status = match($attempt->status) {
                'IN_PROGRESS'  => 'In Progress',
                'SUBMITTED'    => 'Submitted',
                'UNDER_REVIEW' => 'Under Review',
                'GRADED'       => 'Graded',
                default        => 'Submitted',
            };
        }

        return [
            'id'              => $q->id,
            'title'           => $q->title,
            'description'     => $q->description,
            'difficulty'      => ucfirst($q->difficulty),
            'time_limit_mins' => $q->time_limit_mins,
            'total_marks'     => $q->total_marks,
            'start_at'        => $q->start_at ? $q->start_at->toIso8601String() : null,
            'deadline_at'     => $q->deadline_at ? $q->deadline_at->toIso8601String() : null,
            'document_name'   => $q->document ? $q->document->name : 'Class Material',
            'status'          => $status,
            'attempt_id'      => $attempt?->id,
            'score_percentage'=> $attempt?->score_percentage,
            'grade'           => $attempt?->grade,
        ];
    }

    private function formatAttemptForTeacher(QuizAttempt $a): array
    {
        return [
            'id'                   => $a->id,
            'student_id'           => $a->user_id,
            'student_name'         => $a->user ? $a->user->name : 'Student',
            'student_email'        => $a->user ? $a->user->email : '',
            'status'               => $a->status,
            'score_percentage'     => $a->score_percentage,
            'total_marks_obtained' => $a->total_marks_obtained,
            'grade'                => $a->grade,
            'teacher_feedback'     => $a->teacher_feedback,
            'time_spent_seconds'   => $a->time_spent_seconds,
            'completed_at'         => $a->completed_at ? $a->completed_at->toDateTimeString() : null,
            'answers'              => $a->answers->map(fn($ans) => [
                'id'                 => $ans->id,
                'quiz_question_id'   => $ans->quiz_question_id,
                'question_text'      => $ans->question ? $ans->question->question_text : '',
                'question_type'      => $ans->question ? $ans->question->type : 'mcq',
                'options'            => $ans->question ? $ans->question->options : null,
                'correct_answer'     => $ans->question ? $ans->question->correct_answer : '',
                'max_marks'          => $ans->question ? $ans->question->marks : 10,
                'student_answer'     => $ans->student_answer,
                'is_correct'         => $ans->is_correct,
                'score_awarded'      => $ans->score_awarded,
                'teacher_feedback'   => $ans->teacher_feedback,
                'is_manually_graded' => $ans->is_manually_graded,
            ]),
        ];
    }

    private function formatAttemptForStudent(QuizAttempt $a, Quiz $q): array
    {
        $canShowDetails = ($a->status === 'GRADED') || $q->show_results_immediately;

        return [
            'id'                   => $a->id,
            'quiz_title'           => $q->title,
            'status'               => $a->status,
            'score_percentage'     => $canShowDetails ? $a->score_percentage : null,
            'total_marks_obtained' => $canShowDetails ? $a->total_marks_obtained : null,
            'total_possible_marks' => $q->total_marks,
            'grade'                => $canShowDetails ? $a->grade : null,
            'teacher_feedback'     => $canShowDetails ? $a->teacher_feedback : 'Feedback pending teacher evaluation.',
            'time_spent_seconds'   => $a->time_spent_seconds,
            'completed_at'         => $a->completed_at ? $a->completed_at->toDateTimeString() : null,
            'answers'              => $canShowDetails ? $a->answers->map(fn($ans) => [
                'id'               => $ans->id,
                'question_text'    => $ans->question ? $ans->question->question_text : '',
                'options'          => $ans->question ? $ans->question->options : null,
                'student_answer'   => $ans->student_answer,
                'correct_answer'   => $ans->question ? $ans->question->correct_answer : '',
                'is_correct'       => $ans->is_correct,
                'score_awarded'    => $ans->score_awarded,
                'max_marks'        => $ans->question ? $ans->question->marks : 10,
                'teacher_feedback' => $ans->teacher_feedback,
            ]) : [],
        ];
    }

    private function calculateGradeLetter(int $percentage): string
    {
        if ($percentage >= 90) return 'A+';
        if ($percentage >= 80) return 'A';
        if ($percentage >= 70) return 'B';
        if ($percentage >= 60) return 'C';
        if ($percentage >= 50) return 'D';
        return 'F';
    }

    private function extractText(Document $doc): string
    {
        if (!$doc->file_path) return "Title: {$doc->name}";
        $path = storage_path('app/public/' . $doc->file_path);
        if (file_exists($path) && strtolower($doc->file_type) === 'txt') {
            return file_get_contents($path);
        }
        return "Material Title: {$doc->name}\nSubject: {$doc->subject}";
    }

    private function generateFallbackQuestions(Document $doc, int $count, string $type): array
    {
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $list[] = [
                'type'           => $type === 'mixed' ? ($i % 2 === 0 ? 'mcq' : 'short') : $type,
                'question_text'  => "Sample question #" . ($i + 1) . " on " . ($doc->subject ?: 'course material'),
                'options'        => ["A) Core concept", "B) Secondary theory", "C) Irrelevant detail", "D) Null hypothesis"],
                'correct_answer' => "A) Core concept",
                'explanation'    => "Validates key domain knowledge.",
                'marks'          => 10,
            ];
        }
        return $list;
    }

    private function callAI(array $messages, int $maxTokens = 1500): string
    {
        $baseUrl = rtrim(config('services.ai.gateway_url'), '/');
        $apiKey  = config('services.ai.key');
        $model   = config('services.ai.model');

        if (!$apiKey) return '';

        $res = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'X-Title'       => 'Study Assistant',
        ])->timeout(90)->post("{$baseUrl}/chat/completions", [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ]);

        if ($res->failed()) return '';
        return $res->json('choices.0.message.content') ?? '';
    }
}
