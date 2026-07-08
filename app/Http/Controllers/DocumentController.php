<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
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

    private function format(Document $d): array
    {
        return [
            'id'           => $d->id,
            'name'         => $d->name,
            'subject'      => $d->subject ?? 'General',
            'date'         => $d->created_at->format('Y-m-d'),
            'size'         => $d->file_size_formatted,
            'status'       => $d->status,
            'type'         => $d->file_type,
            'file_path'    => $d->file_path,
        ];
    }
}
