<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TaskComment */
class TaskCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'user_id' => $this->user_id,
            'parent_id' => $this->parent_id,
            'comment' => $this->comment,
            'attachments' => $this->relationLoaded('attachmentFiles') && $this->attachmentFiles->isNotEmpty()
                ? $this->attachmentFiles->map(fn ($att) => [
                    'id' => $att->id,
                    'file_name' => $att->file_name,
                    'file_size' => $att->file_size,
                    'mime_type' => $att->mime_type,
                    'url' => $att->url,
                    'download_url' => $att->download_url,
                    'is_image' => $att->is_image,
                    'is_pdf' => $att->is_pdf,
                ])->values()
                : ($this->attachments ?? []),
            'is_edited' => (bool) $this->is_edited,
            'edits_count' => $this->relationLoaded('edits') ? $this->edits->count() : ($this->is_edited ? 1 : 0),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'avatar_url' => $this->user->avatar_url ?? null,
                'role' => $this->user->role ? [
                    'id' => $this->user->role->id,
                    'name' => $this->user->role->name,
                    'slug' => $this->user->role->slug,
                ] : null,
            ] : null),
            'mentions' => $this->whenLoaded('mentions', fn () => $this->mentions->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])),
            'replies' => TaskCommentResource::collection($this->whenLoaded('replies')),
            'can_edit' => $actor && (int) $this->user_id === (int) $actor->id,
            'can_delete' => $actor && (
                (int) $this->user_id === (int) $actor->id
                || $actor->hasRole(\App\Models\Role::SUPER_ADMIN, 'admin')
                || (isset($this->task->project) && (int) $this->task->project->team_lead_id === (int) $actor->id)
                || (isset($this->task->project) && (int) $this->task->project->manager_id === (int) $actor->id)
            ),
        ];
    }
}
