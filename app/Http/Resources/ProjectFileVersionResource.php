<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectFileVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $token = $request->bearerToken() ?? $request->query('token');
        $tokenParam = $token ? ('?token=' . urlencode($token)) : '';

        $downloadUrl = url("/api/v1/projects/{$this->projectFile->project_id}/files/{$this->project_file_id}/versions/{$this->id}/download") . $tokenParam;

        return [
            'id' => $this->id,
            'project_file_id' => $this->project_file_id,
            'version' => $this->version,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_size' => (int) $this->file_size,
            'mime_type' => $this->mime_type,
            'description' => $this->description,
            'uploaded_by' => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
                'role' => $this->uploader->role?->name ?? 'User',
            ] : null,
            'download_url' => $downloadUrl,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
