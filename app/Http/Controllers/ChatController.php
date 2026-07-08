<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    // POST /chats — create new chat
    public function store(Request $request)
    {
        $chat = Chat::create([
            'user_id' => $request->user()->id,
            'title'   => 'New Chat',
        ]);

        return response()->json(['chat' => $chat], 201);
    }

    // GET /chats/{id}/messages — load messages for a chat
    public function messages(Request $request, string $id)
    {
        $chat = Chat::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['messages' => $chat->messages]);
    }

    // POST /chats/{id}/message — send a message and get AI reply
    public function sendMessage(Request $request, string $id)
    {
        $request->validate(['message' => 'required|string|max:4000']);

        $chat = Chat::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Build history BEFORE saving new user message (avoids duplicate in context)
        $history = $chat->messages()
            ->orderBy('created_at', 'desc')
            ->limit(19)
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

    private function callAI(array $history): string
    {
        $apiKey = config('services.openrouter.key');

        if (!$apiKey) {
            return "AI is not configured. Please add OPENROUTER_API_KEY to the backend .env file.";
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => 'Study Assistant',
        ])->timeout(30)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model'    => 'openai/gpt-3.5-turbo',
            'messages' => array_merge(
                [[
                    'role'    => 'system',
                    'content' => 'You are an intelligent study assistant. Help students understand academic topics clearly and concisely. Use examples, bullet points, and structured explanations when helpful.',
                ]],
                $history
            ),
            'max_tokens' => 1024,
        ]);

        if ($response->failed()) {
            return "Sorry, I couldn't get a response right now. Please try again.";
        }

        return $response->json('choices.0.message.content')
            ?? "Sorry, I received an unexpected response. Please try again.";
    }
}
