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
            'avatar_url' => $this->avatar_url,
            'allow_member_invites' => (bool) $this->allow_member_invites,
            'max_participants' => (int) ($this->max_participants ?? 100),
            'display_name' => $viewer ? $this->displayNameFor($viewer) : ($this->title ?? 'Chat'),
            'is_muted' => $viewer ? (bool) ($this->participants?->firstWhere('user_id', $viewer->id)?->is_muted ?? false) : false,
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
                        'is_online' => $p->user ? $p->user->isOnline() : false,
                        'last_seen_at' => $p->user?->last_seen_at?->toISOString(),
                        'last_read_at' => $p->last_read_at?->toISOString(),
                        'is_muted' => (bool) $p->is_muted,
                    ];
                });
            }),
            'partner' => $this->when($this->isDirect() && $viewer, function () use ($viewer) {
                $other = $this->users->first(fn ($u) => (int) $u->id !== (int) $viewer->id);
                if (! $other) return null;
                return [
                    'id' => $other->id,
                    'name' => $other->name,
                    'avatar_url' => $other->avatar_url,
                    'is_online' => $other->isOnline(),
                    'last_seen_at' => $other->last_seen_at?->toISOString(),
                ];
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
