<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_message_id' => $this->chat_message_id,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_size' => (int) $this->file_size,
            'mime_type' => $this->mime_type,
            'url' => $this->url,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
