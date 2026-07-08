<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasUuids;

    protected $fillable = ['chat_id', 'role', 'content'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
