<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_FILE = 'file';
    public const TYPE_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'message',
        'type',
        'reply_to_id',
        'task_id',
        'metadata',
        'is_edited',
        'edited_at',
        'is_pinned',
        'pinned_at',
        'pinned_by_id',
        'deleted_by_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_edited' => 'boolean',
            'edited_at' => 'datetime',
            'is_pinned' => 'boolean',
            'pinned_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class);
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_message_mentions', 'chat_message_id', 'user_id')
            ->withTimestamps();
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }
}
