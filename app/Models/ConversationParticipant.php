<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    use HasFactory;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'last_read_message_id',
        'last_read_at',
        'is_muted',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'is_muted' => 'boolean',
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

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
