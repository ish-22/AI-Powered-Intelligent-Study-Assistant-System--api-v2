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

        // Return cached quiz only if the count matches
        if (!empty($doc->quiz_data) && count($doc->quiz_data) === $count) {
            return response()->json(['quiz' => $doc->quiz_data]);
        }

        $text = $this->extractText($doc);

        if (!$text) {
            return response()->json(['error' => 'Could not read document content. Only TXT files are supported for extraction at this time.'], 422);
        }

        $truncated = mb_substr($text, 0, 5000);

        // Scale max_tokens: ~150 tokens per question + overhead
        $maxTokens = min(4000, 300 + ($count * 150));

        $rawJson = $this->callAI([
            [
                'role'    => 'system',
                'content' => "You are an expert quiz generator. Generate exactly {$count} multiple-choice questions from the given document. Respond ONLY with a valid JSON array in this exact format, no other text: [{\"question\": \"...\", \"options\": [\"A) ...\", \"B) ...\", \"C) ...\", \"D) ...\"], \"answer\": \"A) ...\"}]",
            ],
            [
                'role'    => 'user',
                'content' => "Generate exactly {$count} multiple-choice quiz questions from this document titled \"{$doc->name}\" (subject: {$doc->subject}):\n\n{$truncated}",
            ],
        ], $maxTokens);

        // Parse the JSON from AI response
        preg_match('/\[.*\]/s', $rawJson, $matches);
        $quiz = null;
        if (!empty($matches[0])) {
            $quiz = json_decode($matches[0], true);
        }

        if (!$quiz) {
            return response()->json(['error' => 'Failed to parse quiz from AI response. Please try again.'], 422);
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
