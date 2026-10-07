<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatProvisioningService
{
    /**
     * Synchronize conversation participants for a project discussion channel
     * to strictly match current team lead, manager, and active team members.
     */
    public function syncProjectParticipants(Project $project): void
    {
        $conversation = Conversation::where('project_id', $project->id)
            ->where('type', Conversation::TYPE_PROJECT)
            ->first();

        if (! $conversation) {
            return;
        }

        $allowedUserIds = collect();
        if ($project->team_lead_id) {
            $allowedUserIds->push((int) $project->team_lead_id);
        }
        if ($project->manager_id) {
            $allowedUserIds->push((int) $project->manager_id);
        }

        $memberIds = $project->members()
            ->where('users.is_active', true)
            ->pluck('users.id');

        $allowedUserIds = $allowedUserIds->merge($memberIds)->unique()->values();

        // 1. Remove participants who are no longer on the project
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->whereNotIn('user_id', $allowedUserIds)
            ->delete();

        // 2. Ensure current allowed participants are present
        foreach ($allowedUserIds as $userId) {
            $role = ($userId === (int) $project->team_lead_id || $userId === (int) $project->manager_id)
                ? ConversationParticipant::ROLE_ADMIN
                : ConversationParticipant::ROLE_MEMBER;

            ConversationParticipant::updateOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                ],
                [
                    'role' => $role,
                ]
            );
        }
    }

    /**
     * Clean up conversation memberships when an employee is deactivated.
     */
    public function handleUserDeactivated(User $user): void
    {
        // Remove deactivated user from all project conversations
        ConversationParticipant::where('user_id', $user->id)
            ->whereHas('conversation', fn ($q) => $q->where('type', Conversation::TYPE_PROJECT))
            ->delete();
    }

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
