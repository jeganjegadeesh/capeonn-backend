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
            'message' => $this->message,
            'type' => $this->type,
            'reply_to_id' => $this->reply_to_id,
            'reply_to' => $this->whenLoaded('replyTo', function () {
                if (! $this->replyTo) return null;
                return [
                    'id' => $this->replyTo->id,
                    'user_name' => $this->replyTo->user?->name,
                    'message' => $this->replyTo->message,
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
            'attachments' => ChatAttachmentResource::collection($this->whenLoaded('attachments')),
            'metadata' => $this->metadata,
            'is_edited' => (bool) $this->is_edited,
            'edited_at' => $this->edited_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
