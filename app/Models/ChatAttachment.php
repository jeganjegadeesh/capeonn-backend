<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ChatAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_message_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
    ];

    protected $appends = ['url'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }
}
