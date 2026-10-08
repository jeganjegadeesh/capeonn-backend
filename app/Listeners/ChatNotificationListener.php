<?php

namespace App\Listeners;

use App\Events\Chat\MessageSentEvent;
use App\Models\ConversationParticipant;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

class ChatNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(MessageSentEvent $event): void
    {
        $this->handleMessageSent($event);
    }

    public function handleMessageSent(MessageSentEvent $event): void
    {
        $message = $event->message;
        $conversation = $message->conversation;
        if (! $conversation) {
            return;
        }

        $sender = $message->user;
        $companyId = $conversation->company_id;
        $snippet = Str::limit($message->message ?? 'Sent an attachment', 100);

        // 1. Process @mentions first
        $mentionedUserIds = $message->mentions()->pluck('users.id')->all();
        foreach ($mentionedUserIds as $userId) {
            if ((int) $userId === (int) $sender?->id) {
                continue;
            }

            Notification::create([
                'company_id' => $companyId,
                'user_id'    => $userId,
                'type'       => 'chat_mention',
                'title'      => 'Mentioned by ' . ($sender?->name ?? 'Colleague'),
                'message'    => ($sender?->name ?? 'Colleague') . " mentioned you: \"{$snippet}\"",
                'data'       => [
                    'conversation_id' => $conversation->id,
                    'message_id'      => $message->id,
                ],
            ]);
        }

        // 2. Process general message notifications: collapsed to 1 unread row per conversation
        $participants = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender?->id)
            ->whereNotIn('user_id', $mentionedUserIds)
            ->where('is_muted', false)
            ->get();

        $convTitle = $conversation->title ?? ($conversation->isDirect() ? ($sender?->name ?? 'Direct Chat') : 'Group Chat');

        foreach ($participants as $p) {
            // Find existing unread notification for this conversation
            $existing = Notification::where('user_id', $p->user_id)
                ->where('type', 'chat_message')
                ->where('data->conversation_id', $conversation->id)
                ->whereNull('read_at')
                ->first();

            if ($existing) {
                $data = $existing->data ?? [];
                $unreadCount = ((int) ($data['unread_count'] ?? 1)) + 1;
                $data['unread_count'] = $unreadCount;
                $data['message_id'] = $message->id;

                $existing->update([
                    'message' => ($sender?->name ?? 'Colleague') . ": {$snippet}",
                    'data' => $data,
                    'updated_at' => now(),
                ]);
            } else {
                Notification::create([
                    'company_id' => $companyId,
                    'user_id'    => $p->user_id,
                    'type'       => 'chat_message',
                    'title'      => 'New message in ' . $convTitle,
                    'message'    => ($sender?->name ?? 'Colleague') . ": {$snippet}",
                    'data'       => [
                        'conversation_id' => $conversation->id,
                        'message_id'      => $message->id,
                        'unread_count'    => 1,
                    ],
                ]);
            }
        }
    }
}
