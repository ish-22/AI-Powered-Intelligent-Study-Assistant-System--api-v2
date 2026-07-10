<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $docs = Document::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($d) => $this->format($d));

        return response()->json(['documents' => $docs]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file'    => 'required|file|mimes:pdf,docx,doc,txt|max:51200',
            'subject' => 'nullable|string|max:100',
        ]);

        $file         = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $ext          = strtolower($file->getClientOriginalExtension());
        $path         = $file->store('documents/' . $request->user()->id, 'public');

        $doc = Document::create([
            'user_id'       => $request->user()->id,
            'name'          => $originalName,
            'original_name' => $originalName,
            'file_path'     => $path,
            'file_type'     => $ext,
            'file_size'     => $file->getSize(),
            'subject'       => $request->input('subject', 'General'),
            'status'        => 'Analyzed',
        ]);

        return response()->json(['document' => $this->format($doc)], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $doc = Document::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        Storage::disk('public')->delete($doc->file_path);
        $doc->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    /**
     * POST /documents/{id}/summary
     * Generate an AI summary for the document.
     */
    public function generateSummary(Request $request, string $id)
    {
        $doc = Document::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Return cached summary if it exists
        if (!empty($doc->summary)) {
            return response()->json(['summary' => $doc->summary]);
        }

        // Read file text content
        $text = $this->extractText($doc);

        if (!$text) {
            return response()->json(['error' => 'Could not read document content. Only TXT files are supported for extraction at this time.'], 422);
        }

        // Truncate to ~6000 chars to stay within token limits
        $truncated = mb_substr($text, 0, 6000);

        $summary = $this->callAI([
            [
                'role'    => 'system',
                'content' => 'You are an expert academic summarizer. Given a document, produce a clear, well-structured summary with: 1) A brief overview paragraph, 2) Key points as bullet list, 3) Important terms/concepts. Use markdown formatting.',
            ],
            [
                'role'    => 'user',
                'content' => "Please summarize the following document titled \"{$doc->name}\" (subject: {$doc->subject}):\n\n{$truncated}",
            ],
        ]);

        // Cache the summary in the database
        $doc->update(['summary' => $summary]);

        return response()->json(['summary' => $summary]);
    }

    /**
     * POST /documents/{id}/quiz
     * Generate AI quiz questions for the document.
     */
    public function generateQuiz(Request $request, string $id)
    {
        $doc = Document::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Validate & default the count (1–100)
        $count = (int) $request->input('count', 10);
        $count = max(1, min(100, $count));
        
        $difficulty = $request->input('difficulty', 'medium');
        $topic = $request->input('topic', '');

        // Return cached quiz only if the count matches AND no custom topic/difficulty
        if (empty($topic) && $difficulty === 'medium' && !empty($doc->quiz_data) && count($doc->quiz_data) === $count) {
            return response()->json(['quiz' => $doc->quiz_data]);
        }

        $text = $this->extractText($doc);

        if (!$text) {
            return response()->json(['error' => 'Could not read document content. Only TXT files are supported for extraction at this time.'], 422);
        }

        $truncated = mb_substr($text, 0, 5000);

        // Scale max_tokens: ~150 tokens per question + overhead
        $maxTokens = min(4000, 300 + ($count * 150));

        $difficultyPrompt = "The questions should be at a {$difficulty} difficulty level.";
        $topicPrompt = $topic ? "Focus heavily on the specific topic: '{$topic}'." : "";

        $rawJson = $this->callAI([
            [
                'role'    => 'system',
                'content' => "You are an expert quiz generator. Generate exactly {$count} multiple-choice questions from the given document. {$difficultyPrompt} {$topicPrompt} IMPORTANT: The correct answer must be placed randomly among the options — do NOT always put it as option A. Vary which option (A, B, C, or D) is correct across questions. Respond ONLY with a valid JSON array, no other text: [{\"question\": \"...\", \"options\": [\"A) ...\", \"B) ...\", \"C) ...\", \"D) ...\"], \"answer\": \"B) ...\", \"explanation\": \"...\"}]",
            ],
            [
                'role'    => 'user',
                'content' => "Generate exactly {$count} multiple-choice quiz questions from this document titled \"{$doc->name}\" (subject: {$doc->subject}). Make sure correct answers are distributed across A, B, C, and D options — not always A:\n\n{$truncated}",
            ],
        ], $maxTokens);

        $quiz = null;

        if (str_contains($rawJson, 'AI is not configured') || str_contains($rawJson, 'AI request failed') || str_contains($rawJson, 'Unexpected AI response')) {
            $quiz = $this->generateFallbackQuiz($doc, $count, $difficulty, $topic);
        } else {
            preg_match('/\[.*\]/s', $rawJson, $matches);
            if (!empty($matches[0])) {
                $parsed = json_decode($matches[0], true);
                if (is_array($parsed)) {
                    $quiz = array_map(fn($q) => $this->shuffleOptions($q), $parsed);
                }
            }
        }

        if (!$quiz || !is_array($quiz)) {
            return response()->json(['error' => 'Failed to generate quiz. Please try again.'], 422);
        }

        $doc->update(['quiz_data' => $quiz]);

        return response()->json(['quiz' => $quiz]);
    }

    // ---- Helpers ----------------------------------------------------------------

    /**
     * Extract plain text from a document file.
     * Currently supports .txt files directly.
     * PDF/DOCX extraction requires additional libraries (e.g. smalot/pdfparser).
     */
    private function extractText(Document $doc): ?string
    {
        if (!$doc->file_path) return null;

        $absolutePath = Storage::disk('public')->path($doc->file_path);

        if (!file_exists($absolutePath)) return null;

        $ext = strtolower($doc->file_type);

        if ($ext === 'txt') {
            return file_get_contents($absolutePath);
        }

        // For PDF/DOCX: return a fallback prompt with just filename+subject
        // so AI can still generate a generic/topic-based summary
        return "Document Name: {$doc->name}\nSubject: {$doc->subject}\n[Full text extraction not available for {$ext} files. Generate a general academic overview based on the title and subject.]";
    }

    /**
     * Shuffle the options of a question and update the answer to match the new position.
     */
    private function shuffleOptions(array $q): array
    {
        if (empty($q['options']) || empty($q['answer'])) return $q;

        $labels      = ['A', 'B', 'C', 'D'];
        $correctText = trim(preg_replace('/^[A-D]\)\s*/i', '', trim($q['answer'])));

        // Strip labels → plain texts, keeping original indices
        $texts = array_values(array_map(
            fn($o) => trim(preg_replace('/^[A-D]\)\s*/i', '', trim($o))),
            $q['options']
        ));

        // Find which index holds the correct answer BEFORE shuffling
        $correctIdx = array_search($correctText, $texts, true);

        // If not found by exact match, fall back to case-insensitive search
        if ($correctIdx === false) {
            foreach ($texts as $i => $t) {
                if (strcasecmp($t, $correctText) === 0) {
                    $correctIdx = $i;
                    break;
                }
            }
        }

        // Build index map, shuffle it, then reorder texts by the shuffled map
        $indices = array_keys($texts);
        shuffle($indices);
        $shuffled = array_map(fn($i) => $texts[$i], $indices);

        // Find where the correct text ended up after shuffle
        $newCorrectPos = array_search($correctIdx, $indices, true);

        $newOptions = [];
        foreach ($shuffled as $i => $text) {
            $newOptions[] = "{$labels[$i]}) {$text}";
        }

        $newAnswer = $newCorrectPos !== false
            ? "{$labels[$newCorrectPos]}) {$shuffled[$newCorrectPos]}"
            : "{$labels[0]}) {$shuffled[0]}";

        return array_merge($q, ['options' => $newOptions, 'answer' => $newAnswer]);
    }

    private function generateFallbackQuiz(Document $doc, int $count, string $difficulty, string $topic): array
    {
        $subject = $doc->subject ?: 'General';
        $title   = $doc->name;
        $focus   = $topic ?: $subject;
        $difficultyLabel = $difficulty === 'hard' ? 'challenging' : ($difficulty === 'easy' ? 'beginner-friendly' : 'balanced');

        $baseTopics = ['main idea', 'key concept', 'important detail', 'practical application', 'cause and effect'];
        $questions  = [];

        for ($i = 0; $i < $count; $i++) {
            $topicLabel = $baseTopics[$i % count($baseTopics)];
            $q = [
                'question'    => "What is the most important {$topicLabel} covered in {$title}?",
                'options'     => [
                    'A) The central point of the material',
                    'B) A minor example mentioned briefly',
                    'C) An unrelated fact',
                    'D) A formatting detail',
                ],
                'answer'      => 'A) The central point of the material',
                'explanation' => "This {$difficultyLabel} quiz question checks understanding of the core idea in {$title} for {$focus}.",
            ];
            $questions[] = $this->shuffleOptions($q);
        }

        return $questions;
    }

    private function callAI(array $messages, int $maxTokens = 1500): string
    {
        $apiKey = config('services.openrouter.key');

        if (!$apiKey) {
            return 'AI is not configured. Please add OPENROUTER_API_KEY to the backend .env file.';
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => 'Study Assistant',
        ])->timeout(120)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model'      => 'openai/gpt-3.5-turbo',
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ]);

        if ($response->failed()) {
            return 'AI request failed. Please try again later.';
        }

        return $response->json('choices.0.message.content')
            ?? 'Unexpected AI response. Please try again.';
    }

    private function format(Document $d): array
    {
        return [
            'id'        => $d->id,
            'name'      => $d->name,
            'subject'   => $d->subject ?? 'General',
            'date'      => $d->created_at->format('Y-m-d'),
            'size'      => $d->file_size_formatted,
            'status'    => $d->status,
            'type'      => $d->file_type,
            'file_path' => $d->file_path,
            'summary'   => $d->summary,
            'quiz_data' => $d->quiz_data,
        ];
    }
}
