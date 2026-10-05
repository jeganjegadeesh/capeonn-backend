<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'display_name' => $viewer ? $this->displayNameFor($viewer) : ($this->title ?? 'Chat'),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', function () {
                if (! $this->project) return null;
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'code' => $this->project->code,
                    'status' => $this->project->status,
                ];
            }),
            'created_by' => $this->whenLoaded('createdBy', function () {
                if (! $this->createdBy) return null;
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
            'participants' => $this->whenLoaded('participants', function () {
                return $this->participants->map(function ($p) {
                    return [
                        'user_id' => $p->user_id,
                        'name' => $p->user?->name,
                        'avatar_url' => $p->user?->avatar_url,
                        'role' => $p->role,
                        'role_slug' => $p->user?->role?->slug,
                        'last_read_at' => $p->last_read_at?->toISOString(),
                        'is_muted' => (bool) $p->is_muted,
                    ];
                });
            }),
            'latest_message' => $this->whenLoaded('latestMessage', function () {
                if (! $this->latestMessage) return null;
                return new ChatMessageResource($this->latestMessage);
            }),
            'unread_count' => $viewer ? $this->unreadCountFor($viewer) : 0,
            'last_message_at' => $this->last_message_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
