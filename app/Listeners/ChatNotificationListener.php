<?php

namespace App\Listeners;

use App\Events\Chat\MessageSentEvent;
use App\Models\ConversationParticipant;
use App\Models\Notification;
use Illuminate\Support\Str;

class ChatNotificationListener
{
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

        // 2. Process general message notification for non-muted participants
        $participants = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender?->id)
            ->whereNotIn('user_id', $mentionedUserIds)
            ->where('is_muted', false)
            ->get();

        $convTitle = $conversation->title ?? ($conversation->isDirect() ? ($sender?->name ?? 'Direct Chat') : 'Group Chat');

        foreach ($participants as $p) {
            Notification::create([
                'company_id' => $companyId,
                'user_id'    => $p->user_id,
                'type'       => 'chat_message',
                'title'      => 'New message in ' . $convTitle,
                'message'    => ($sender?->name ?? 'Colleague') . ": {$snippet}",
                'data'       => [
                    'conversation_id' => $conversation->id,
                    'message_id'      => $message->id,
                ],
            ]);
        }
    }
}
