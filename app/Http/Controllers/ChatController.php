<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    // GET /chats — list user's chats
    public function index(Request $request)
    {
        $chats = Chat::where('user_id', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title', 'updated_at']);

        return response()->json(['chats' => $chats]);
    }

    // POST /chats — create new chat (optionally with a document context)
    public function store(Request $request)
    {
        $request->validate(['document_id' => 'sometimes|string']);

        $title   = 'New Chat';
        $context = null;

        if ($request->filled('document_id')) {
            $doc = Document::where('id', $request->document_id)
                ->where('user_id', $request->user()->id)
                ->first();

            if ($doc) {
                $title = "Chat about: {$doc->name}";

                // Build context: prefer summary, then extract raw text, then just metadata
                if (!empty($doc->summary)) {
                    $context = "Document: {$doc->name} (Subject: {$doc->subject})\n\nSummary:\n{$doc->summary}";
                } else {
                    $text = $this->extractDocText($doc);
                    $context = $text
                        ? "Document: {$doc->name} (Subject: {$doc->subject})\n\nContent:\n" . mb_substr($text, 0, 4000)
                        : "Document: {$doc->name} (Subject: {$doc->subject})\n\n[Full text not available — answer based on the title and subject.]";
                }
            }
        }

        $chat = Chat::create([
            'user_id' => $request->user()->id,
            'title'   => $title,
        ]);

        // Inject document context as a hidden system message so AI always knows the file
        if ($context) {
            ChatMessage::create([
                'chat_id' => $chat->id,
                'role'    => 'system',
                'content' => "You are an AI study assistant. The student is asking about the following document. Use it as your primary knowledge source for this conversation.\n\n{$context}",
            ]);
        }

        return response()->json(['chat' => $chat], 201);
    }

    // GET /chats/{id}/messages — load messages for a chat (exclude system messages from UI)
    public function messages(Request $request, string $id)
    {
        $chat = Chat::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $messages = $chat->messages()->where('role', '!=', 'system')->get();

        return response()->json(['messages' => $messages]);
    }

    // POST /chats/{id}/message — send a message and get AI reply
    public function sendMessage(Request $request, string $id)
    {
        $request->validate(['message' => 'required|string|max:4000']);

        $chat = Chat::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Build history — include system messages (document context) but cap total at 20
        $history = $chat->messages()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();

        // Append current user message to history
        $history[] = ['role' => 'user', 'content' => $request->message];

        // Save user message to DB
        ChatMessage::create([
            'chat_id' => $chat->id,
            'role'    => 'user',
            'content' => $request->message,
        ]);

        // Call OpenRouter AI
        $aiContent = $this->callAI($history);

        // Save AI reply
        $aiMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'role'    => 'assistant',
            'content' => $aiContent,
        ]);

        // Auto-title the chat from first user message
        if ($chat->title === 'New Chat') {
            $chat->update(['title' => mb_substr($request->message, 0, 60)]);
        }

        $chat->touch();

        return response()->json([
            'message' => [
                'id'         => $aiMessage->id,
                'role'       => 'assistant',
                'content'    => $aiContent,
                'created_at' => $aiMessage->created_at,
            ],
            'chat_title' => $chat->title,
        ]);
    }

    // DELETE /chats/{id}
    public function destroy(Request $request, string $id)
    {
        Chat::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail()
            ->delete();

        return response()->json(['message' => 'Chat deleted']);
    }

    private function extractDocText(Document $doc): ?string
    {
        if (!$doc->file_path) return null;
        $path = Storage::disk('public')->path($doc->file_path);
        if (!file_exists($path)) return null;
        if (strtolower($doc->file_type) === 'txt') return file_get_contents($path);
        return null;
    }

    private function callAI(array $history): string
    {
        $apiKey = config('services.openrouter.key');

        if (!$apiKey) {
            return "AI is not configured. Please add OPENROUTER_API_KEY to the backend .env file.";
        }

        // Only prepend the generic system prompt if history has no system message already
        $hasSystem = collect($history)->contains(fn($m) => ($m['role'] ?? '') === 'system');
        $messages  = $hasSystem ? $history : array_merge(
            [['role' => 'system', 'content' => 'You are an intelligent study assistant. Help students understand academic topics clearly and concisely. Use examples, bullet points, and structured explanations when helpful.']],
            $history
        );

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => 'Study Assistant',
        ])->timeout(30)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model'      => 'openai/gpt-3.5-turbo',
            'messages'   => $messages,
            'max_tokens' => 1024,
        ]);

        if ($response->failed()) {
            return "Sorry, I couldn't get a response right now. Please try again.";
        }

        return $response->json('choices.0.message.content')
            ?? "Sorry, I received an unexpected response. Please try again.";
    }
}
