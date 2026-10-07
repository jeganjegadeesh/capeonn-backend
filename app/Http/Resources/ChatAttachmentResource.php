<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $token = $request->bearerToken() ?? $request->query('token');
        $tokenParam = $token ? ('?token=' . urlencode($token)) : '';
        $previewTokenParam = $token ? ('&token=' . urlencode($token)) : '';

        $url = $this->url . $tokenParam;
        $downloadUrl = $this->download_url . $tokenParam;
        $previewUrl = $this->preview_url . $previewTokenParam;

        return [
            'id' => $this->id,
            'chat_message_id' => $this->chat_message_id,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_size' => (int) $this->file_size,
            'mime_type' => $this->mime_type,
            'url' => $url,
            'download_url' => $downloadUrl,
            'preview_url' => $previewUrl,
            'thumbnail_url' => str_starts_with($this->mime_type ?? '', 'image/') ? $previewUrl : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
