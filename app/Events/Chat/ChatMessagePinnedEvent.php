<?php

namespace App\Events\Chat;

use App\Http\Resources\ChatMessageResource;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessagePinnedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message,
        public bool $isPinned = true
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.pinned';
    }

    public function broadcastWith(): array
    {
        $this->message->loadMissing(['user.role', 'replyTo.user', 'task', 'attachments', 'mentions', 'pinnedBy']);
        return [
            'is_pinned' => $this->isPinned,
            'message' => (new ChatMessageResource($this->message))->resolve(),
        ];
    }
}
