<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_DIRECT = 'direct';
    public const TYPE_GROUP = 'group';
    public const TYPE_PROJECT = 'project';

    protected $fillable = [
        'company_id',
        'type',
        'title',
        'avatar_url',
        'allow_member_invites',
        'max_participants',
        'project_id',
        'created_by_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'allow_member_invites' => 'boolean',
            'max_participants' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'last_read_message_id', 'last_read_at', 'is_muted'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public function isProject(): bool
    {
        return $this->type === self::TYPE_PROJECT;
    }

    /**
     * Resolve the display title for a viewing user.
     */
    public function displayNameFor(User $viewer): string
    {
        if ($this->isDirect()) {
            $other = $this->users->first(fn ($u) => (int) $u->id !== (int) $viewer->id);
            return $other ? ($other->name ?? 'Direct Chat') : 'Direct Chat';
        }

        if ($this->isProject() && $this->project) {
            return $this->project->name . ' Discussion';
        }

        return $this->title ?? 'Group Chat';
    }

    /**
     * Compute unread message count for a participant.
     */
    public function unreadCountFor(User $viewer): int
    {
        $participant = $this->participants->firstWhere('user_id', $viewer->id);
        if (! $participant) {
            return 0;
        }

        $query = $this->messages()->where('user_id', '!=', $viewer->id);

        if ($participant->last_read_message_id) {
            $query->where('id', '>', $participant->last_read_message_id);
        } elseif ($participant->last_read_at) {
            $query->where('created_at', '>', $participant->last_read_at);
        }

        return $query->count();
    }
}
