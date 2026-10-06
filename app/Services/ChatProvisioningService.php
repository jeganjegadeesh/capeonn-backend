<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatProvisioningService
{
    /**
     * Connect a newly created employee with all active colleagues in their company
     * via direct chats, seeded with a welcome/introduction message.
     */
    public function connectNewEmployeeToAll(User $newUser): int
    {
        if (! $newUser->company_id || ! $newUser->is_active) {
            return 0;
        }

        $colleagues = User::where('company_id', $newUser->company_id)
            ->where('id', '!=', $newUser->id)
            ->where('is_active', true)
            ->get();

        if ($colleagues->isEmpty()) {
            return 0;
        }

        $connectedCount = 0;

        foreach ($colleagues as $colleague) {
            try {
                $connected = $this->createDirectChatIfNotExists($newUser, $colleague);
                if ($connected) {
                    $connectedCount++;
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to auto-connect chat between {$newUser->id} and {$colleague->id}: " . $e->getMessage());
            }
        }

        return $connectedCount;
    }

    /**
     * Connect all active employees of a company together (useful for retroactive seeding).
     */
    public function connectAllEmployees(int $companyId): int
    {
        $users = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        $connectedCount = 0;

        for ($i = 0; $i < $users->count(); $i++) {
            for ($j = $i + 1; $j < $users->count(); $j++) {
                $userA = $users[$i];
                $userB = $users[$j];

                if ($this->createDirectChatIfNotExists($userA, $userB)) {
                    $connectedCount++;
                }
            }
        }

        return $connectedCount;
    }

    /**
     * Create direct chat between two users if one does not already exist.
     */
    public function createDirectChatIfNotExists(User $userA, User $userB): bool
    {
        if ((int) $userA->company_id !== (int) $userB->company_id) {
            return false;
        }

        $existing = Conversation::where('company_id', $userA->company_id)
            ->where('type', Conversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userA->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userB->id))
            ->first();

        if ($existing) {
            return false;
        }

        DB::transaction(function () use ($userA, $userB) {
            $conv = Conversation::create([
                'company_id' => $userA->company_id,
                'type' => Conversation::TYPE_DIRECT,
                'created_by_id' => $userA->id,
                'last_message_at' => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id' => $userA->id,
                'role' => ConversationParticipant::ROLE_MEMBER,
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id' => $userB->id,
                'role' => ConversationParticipant::ROLE_MEMBER,
            ]);

            ChatMessage::create([
                'conversation_id' => $conv->id,
                'user_id' => $userA->id,
                'message' => "Hi {$userB->name}! I'm {$userA->name}. Looking forward to collaborating on Capeonn!",
                'type' => ChatMessage::TYPE_TEXT,
            ]);
        });

        return true;
    }
}
