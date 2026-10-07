<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'task_title' => $this->task?->title,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_size' => (int) $this->file_size,
            'formatted_size' => $this->formatted_size,
            'mime_type' => $this->mime_type,
            'category' => $this->category,
            'version' => (int) ($this->version ?? 1),
            'description' => $this->description,
            'url' => $this->url,
            'download_url' => $this->download_url,
            'preview_url' => $this->preview_url,
            'thumbnail_url' => str_starts_with($this->mime_type ?? '', 'image/') ? $this->preview_url : null,
            'uploader' => [
                'id' => $this->uploader?->id,
                'name' => $this->uploader?->name,
                'avatar_url' => $this->uploader?->avatar_url,
                'role' => $this->uploader?->role?->name,
                'role_slug' => $this->uploader?->role?->slug,
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
