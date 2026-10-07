<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'avatar_url' => $this->user?->avatar_url,
                'role' => $this->user?->role?->name,
                'role_slug' => $this->user?->role?->slug,
            ],
            'message' => $this->trashed() ? 'This message was deleted.' : $this->message,
            'type' => $this->trashed() ? 'deleted' : $this->type,
            'reply_to_id' => $this->reply_to_id,
            'reply_to' => $this->whenLoaded('replyTo', function () {
                if (! $this->replyTo) return null;
                return [
                    'id' => $this->replyTo->id,
                    'user_name' => $this->replyTo->user?->name,
                    'message' => $this->replyTo->trashed() ? 'This message was deleted.' : $this->replyTo->message,
                    'type' => $this->replyTo->type,
                ];
            }),
            'task_id' => $this->task_id,
            'task' => $this->whenLoaded('task', function () {
                if (! $this->task) return null;
                return [
                    'id' => $this->task->id,
                    'title' => $this->task->title,
                    'status' => $this->task->status,
                    'priority' => $this->task->priority,
                ];
            }),
            'attachments' => $this->trashed() ? [] : ChatAttachmentResource::collection($this->whenLoaded('attachments')),
            'mentions' => $this->whenLoaded('mentions', function () {
                return $this->mentions->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ]);
            }),
            'is_mentioned' => $this->relationLoaded('mentions') && $request->user()
                ? $this->mentions->contains('id', $request->user()->id)
                : false,
            'is_pinned' => (bool) $this->is_pinned,
            'pinned_at' => $this->pinned_at?->toISOString(),
            'pinned_by' => $this->whenLoaded('pinnedBy', fn () => $this->pinnedBy ? [
                'id' => $this->pinnedBy->id,
                'name' => $this->pinnedBy->name,
            ] : null),
            'is_deleted' => $this->trashed(),
            'deleted_at' => $this->deleted_at?->toISOString(),
            'deleted_by' => $this->whenLoaded('deletedBy', fn () => $this->deletedBy ? [
                'id' => $this->deletedBy->id,
                'name' => $this->deletedBy->name,
            ] : null),
            'metadata' => $this->metadata,
            'is_edited' => (bool) $this->is_edited,
            'edited_at' => $this->edited_at?->toISOString(),
            'is_seen' => $this->when(
                $this->relationLoaded('conversation') && $this->conversation && $this->conversation->isDirect() && $request->user(),
                function () use ($request) {
                    $otherParticipant = $this->conversation->participants
                        ->first(fn ($p) => (int) $p->user_id !== (int) $request->user()->id);
                    return $otherParticipant && $otherParticipant->last_read_message_id >= $this->id;
                }
            ),
            'read_by_count' => $this->when(
                $this->relationLoaded('conversation') && $this->conversation && ! $this->conversation->isDirect() && $request->user(),
                function () use ($request) {
                    return $this->conversation->participants
                        ->filter(fn ($p) => (int) $p->user_id !== (int) $request->user()->id && $p->last_read_message_id >= $this->id)
                        ->count();
                }
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
