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

    protected $appends = ['url', 'download_url', 'preview_url'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function getUrlAttribute(): string
    {
        $convId = $this->message?->conversation_id;
        if ($convId) {
            return url("/api/v1/conversations/{$convId}/attachments/{$this->id}/download");
        }
        return url("/api/v1/attachments/{$this->id}/download");
    }

    public function getDownloadUrlAttribute(): string
    {
        return $this->getUrlAttribute();
    }

    public function getPreviewUrlAttribute(): string
    {
        return $this->getUrlAttribute() . '?preview=1';
    }
}
