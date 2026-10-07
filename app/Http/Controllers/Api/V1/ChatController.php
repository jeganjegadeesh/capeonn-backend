<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CreateDirectConversationRequest;
use App\Http\Requests\Chat\CreateGroupConversationRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\UserResource;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\ChatProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly ChatProvisioningService $chatProvisioningService
    ) {
    }

    /**
     * List all conversations accessible to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Conversation::query()
            ->with([
                'project:id,name,code,status',
                'createdBy:id,name',
                'participants.user:id,name,role_id,last_seen_at',
                'participants.user.role:id,name,slug',
                'latestMessage.user:id,name',
                'latestMessage.attachments',
            ]);

        $this->accessControl->constrainConversations($query, $user);

        if ($search = $request->query('search')) {
            $escaped = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($escaped) {
                $q->where('title', 'like', "%{$escaped}%")
                  ->orWhereHas('users', fn ($u) => $u->where('name', 'like', "%{$escaped}%"))
                  ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$escaped}%"));
            });
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $paginator = $query->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->paginate($this->perPage($request));

        $items = ConversationResource::collection($paginator->items())->resolve();

        return $this->paginated(
            $paginator,
            $items,
            'Conversations loaded'
        );
    }

    /**
     * Search and list active colleagues within the same company for starting conversations.
     */
    public function colleagues(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->with([
                'department:id,name',
                'designation:id,name',
                'role:id,slug,name,level',
            ])
            ->when(! $request->boolean('include_self', false), fn ($q) => $q->where('id', '!=', $user->id))
            ->when($search !== '', function ($q) use ($search) {
                $escaped = addcslashes($search, '%_\\');
                $q->where(function ($sub) use ($escaped) {
                    $sub->where('name', 'like', "%{$escaped}%")
                        ->orWhere('email', 'like', "%{$escaped}%")
                        ->orWhere('employee_code', 'like', "%{$escaped}%");
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', (int) $request->query('department_id')))
            ->orderBy('name');

        $paginator = $query->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn (User $u) => (new UserResource($u))->resolve())
            ->values()
            ->all();

        return $this->paginated($paginator, $items);
    }

    /**
     * Start or fetch an existing direct conversation with another employee.
     * Prevents race conditions and duplicate chats using atomic locks.
     */
    public function direct(CreateDirectConversationRequest $request): JsonResponse
    {
        $actor = $request->user();
        $otherUserId = (int) $request->validated('user_id');

        $targetUser = User::where('company_id', $actor->company_id)
            ->where('is_active', true)
            ->findOrFail($otherUserId);

        $conversation = DB::transaction(function () use ($actor, $targetUser) {
            // Find existing direct conversation inside transaction
            $existing = Conversation::where('company_id', $actor->company_id)
                ->where('type', Conversation::TYPE_DIRECT)
                ->whereHas('participants', fn ($q) => $q->where('user_id', $actor->id))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $targetUser->id))
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // Create new direct conversation
            $conv = Conversation::create([
                'company_id' => $actor->company_id,
                'type' => Conversation::TYPE_DIRECT,
                'created_by_id' => $actor->id,
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id' => $actor->id,
                'role' => ConversationParticipant::ROLE_MEMBER,
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id' => $targetUser->id,
                'role' => ConversationParticipant::ROLE_MEMBER,
            ]);

            return $conv;
        });

        $conversation->loadMissing([
            'participants.user.role',
            'latestMessage.user',
        ]);

        return $this->success(
            new ConversationResource($conversation),
            'Direct conversation ready',
            $conversation->wasRecentlyCreated ? 201 : 200
        );
    }

    /**
     * Create a group conversation.
     */
    public function group(CreateGroupConversationRequest $request): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validated();

        $participantIds = collect($validated['participant_ids'])
            ->map(fn ($id) => (int) $id)
            ->push((int) $actor->id)
            ->unique()
            ->values();

        if ($participantIds->count() > 100) {
            return $this->error('Group conversations cannot exceed 100 participants.', 422);
        }

        $validCount = User::where('company_id', $actor->company_id)
            ->where('is_active', true)
            ->whereIn('id', $participantIds)
            ->count();

        if ($validCount !== $participantIds->count()) {
            return $this->error('One or more participants are invalid or not in your company.', 422);
        }

        $conversation = DB::transaction(function () use ($actor, $validated, $participantIds) {
            $conv = Conversation::create([
                'company_id' => $actor->company_id,
                'type' => Conversation::TYPE_GROUP,
                'title' => $validated['title'],
                'allow_member_invites' => $validated['allow_member_invites'] ?? true,
                'max_participants' => 100,
                'created_by_id' => $actor->id,
            ]);

            foreach ($participantIds as $userId) {
                ConversationParticipant::create([
                    'conversation_id' => $conv->id,
                    'user_id' => $userId,
                    'role' => ($userId === (int) $actor->id)
                        ? ConversationParticipant::ROLE_ADMIN
                        : ConversationParticipant::ROLE_MEMBER,
                ]);
            }

            return $conv;
        });

        $conversation->loadMissing([
            'createdBy:id,name',
            'participants.user.role',
            'latestMessage.user',
        ]);

        return $this->success(
            new ConversationResource($conversation),
            'Group conversation created',
            201
        );
    }

    /**
     * Get or create the dedicated discussion channel for a Project.
     * Does NOT permanently bind observers who only view.
     */
    public function forProject(Request $request, int $projectId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)
            ->with(['members', 'teamLead', 'manager'])
            ->findOrFail($projectId);

        if (! $this->accessControl->canAccessProject($actor, $project, 'projects.view')) {
            return $this->error('You do not have access to this project discussion.', 403);
        }

        // Find or create project conversation
        $conversation = Conversation::firstOrCreate(
            [
                'company_id' => $actor->company_id,
                'project_id' => $project->id,
                'type' => Conversation::TYPE_PROJECT,
            ],
            [
                'title' => $project->name . ' Discussion',
                'created_by_id' => $project->team_lead_id ?? $actor->id,
            ]
        );

        // Synchronize participants strictly with active members, lead, manager
        $this->chatProvisioningService->syncProjectParticipants($project);

        $conversation->loadMissing([
            'project:id,name,code,status',
            'participants.user.role',
            'latestMessage.user',
        ]);

        return $this->success(
            new ConversationResource($conversation),
            'Project conversation ready'
        );
    }

    /**
     * Get conversation details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)
            ->with([
                'project:id,name,code,status',
                'createdBy:id,name',
                'participants.user.role',
                'latestMessage.user',
            ])
            ->findOrFail($id);

        if (! $this->accessControl->canAccessConversation($actor, $conversation)) {
            return $this->error('You do not have access to this conversation.', 403);
        }

        return $this->success(
            new ConversationResource($conversation),
            'Conversation details loaded'
        );
    }

    /**
     * Update conversation settings (group title, avatar, invite permissions).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($id);

        if (! $this->accessControl->canManageConversation($actor, $conversation)) {
            return $this->error('You do not have permission to manage this conversation.', 403);
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'allow_member_invites' => ['nullable', 'boolean'],
            'max_participants' => ['nullable', 'integer', 'min:2', 'max:500'],
        ]);

        $conversation->update(array_filter([
            'title' => $validated['title'] ?? null,
            'avatar_url' => $validated['avatar_url'] ?? null,
            'allow_member_invites' => $validated['allow_member_invites'] ?? null,
            'max_participants' => $validated['max_participants'] ?? null,
        ], fn ($val) => $val !== null));

        return $this->success(
            new ConversationResource($conversation->fresh(['participants.user.role', 'latestMessage.user'])),
            'Conversation updated successfully'
        );
    }

    /**
     * Add participants to group conversation.
     */
    public function addParticipants(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($id);

        $isParticipant = $conversation->participants()->where('user_id', $actor->id)->exists();
        $isAdmin = $conversation->participants()
            ->where('user_id', $actor->id)
            ->where('role', ConversationParticipant::ROLE_ADMIN)
            ->exists();

        // Check if invites are allowed or actor is admin
        if (! $isAdmin && (! $conversation->allow_member_invites || ! $isParticipant)) {
            return $this->error('Only group administrators can add new members.', 403);
        }

        $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $newIds = collect($request->input('participant_ids'))->unique();
        $currentCount = $conversation->participants()->count();
        $maxAllowed = $conversation->max_participants ?: 100;

        if ($currentCount + $newIds->count() > $maxAllowed) {
            return $this->error("Cannot exceed maximum group limit of {$maxAllowed} members.", 422);
        }

        $validUsers = User::where('company_id', $actor->company_id)
            ->where('is_active', true)
            ->whereIn('id', $newIds)
            ->get();

        foreach ($validUsers as $u) {
            ConversationParticipant::firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $u->id,
                ],
                [
                    'role' => ConversationParticipant::ROLE_MEMBER,
                ]
            );
        }

        return $this->success(
            new ConversationResource($conversation->fresh(['participants.user.role', 'latestMessage.user'])),
            'Participants added successfully'
        );
    }

    /**
     * Remove a participant from a group conversation.
     */
    public function removeParticipant(Request $request, int $id, int $userId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($id);

        $isSelf = (int) $actor->id === $userId;
        $canManage = $this->accessControl->canManageConversation($actor, $conversation);

        if (! $isSelf && ! $canManage) {
            return $this->error('You do not have permission to remove this participant.', 403);
        }

        if ($isSelf) {
            return $this->leave($request, $id);
        }

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->delete();

        return $this->success(null, 'Participant removed successfully');
    }

    /**
     * Leave group conversation with automatic admin transfer if creator/admin leaves.
     */
    public function leave(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($id);

        if ($conversation->isDirect()) {
            return $this->error('You cannot leave a direct conversation.', 422);
        }

        if ($conversation->isProject()) {
            return $this->error('Project discussion channels are tied to project membership.', 422);
        }

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $participant) {
            return $this->error('You are not a participant in this conversation.', 404);
        }

        // If the leaving user is an admin, transfer admin if other members exist
        if ($participant->isAdmin()) {
            $otherParticipants = ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $actor->id)
                ->get();

            if ($otherParticipants->isNotEmpty()) {
                $hasOtherAdmin = $otherParticipants->contains(fn ($p) => $p->isAdmin());
                if (! $hasOtherAdmin) {
                    $otherParticipants->first()->update(['role' => ConversationParticipant::ROLE_ADMIN]);
                }
            }
        }

        $participant->delete();

        return $this->success(null, 'You have left the conversation');
    }

    /**
     * Mute or unmute notifications for a conversation.
     */
    public function mute(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($id);

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $participant) {
            return $this->error('You are not a participant in this conversation.', 403);
        }

        $isMuted = $request->has('is_muted')
            ? $request->boolean('is_muted')
            : ! $participant->is_muted;

        $participant->update(['is_muted' => $isMuted]);

        return $this->success([
            'conversation_id' => $conversation->id,
            'is_muted' => $isMuted,
        ], $isMuted ? 'Conversation muted' : 'Conversation unmuted');
    }

    /**
     * Get chat messages linked to a specific task.
     */
    public function taskMessages(Request $request, int $taskId): JsonResponse
    {
        $actor = $request->user();

        $task = Task::where('company_id', $actor->company_id)->findOrFail($taskId);

        if (! $this->accessControl->canAccessTask($actor, $task)) {
            return $this->error('You do not have access to this task.', 403);
        }

        $query = ChatMessage::where('task_id', $task->id)
            ->whereHas('conversation', function ($convQuery) use ($actor) {
                $this->accessControl->constrainConversations($convQuery, $actor);
            })
            ->with([
                'user.role',
                'replyTo.user',
                'task:id,title,status,priority',
                'attachments',
                'mentions',
                'pinnedBy',
            ]);

        $paginator = $query->orderByDesc('id')->paginate($this->perPage($request));
        $items = ChatMessageResource::collection($paginator->items())->resolve();

        return $this->paginated($paginator, $items, 'Task messages loaded');
    }

    /**
     * Total unread messages count across all conversations.
     */
    public function unreadSummary(Request $request): JsonResponse
    {
        $actor = $request->user();

        $conversations = Conversation::query();
        $this->accessControl->constrainConversations($conversations, $actor);
        $convs = $conversations->with('participants')->get();

        $totalUnread = 0;
        foreach ($convs as $conv) {
            $totalUnread += $conv->unreadCountFor($actor);
        }

        return $this->success([
            'total_unread' => $totalUnread,
        ], 'Unread summary loaded');
    }
}
