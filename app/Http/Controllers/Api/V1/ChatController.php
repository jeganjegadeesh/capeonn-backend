<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CreateDirectConversationRequest;
use App\Http\Requests\Chat\CreateGroupConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function __construct(private readonly AccessControl $accessControl)
    {
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
                'participants.user:id,name,role_id',
                'participants.user.role:id,name,slug',
                'latestMessage.user:id,name',
            ]);

        $this->accessControl->constrainConversations($query, $user);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('users', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        // Order by latest activity first, then newly created
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
     * Start or fetch an existing direct conversation with another employee.
     */
    public function direct(CreateDirectConversationRequest $request): JsonResponse
    {
        $actor = $request->user();
        $otherUserId = (int) $request->validated('user_id');

        $targetUser = User::where('company_id', $actor->company_id)
            ->where('is_active', true)
            ->findOrFail($otherUserId);

        // Find existing 1-on-1 direct conversation between these two users
        $existing = Conversation::where('company_id', $actor->company_id)
            ->where('type', Conversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $actor->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $targetUser->id))
            ->first();

        if ($existing) {
            $existing->loadMissing([
                'participants.user.role',
                'latestMessage.user',
            ]);

            return $this->success(
                new ConversationResource($existing),
                'Direct conversation retrieved'
            );
        }

        // Create new direct conversation
        $conversation = DB::transaction(function () use ($actor, $targetUser) {
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
            'Direct conversation created',
            201
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

        // Verify all participants belong to the same company
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

        // Sync participants from project members + lead + manager + current user
        $participantIds = collect();
        if ($project->team_lead_id) $participantIds->push((int) $project->team_lead_id);
        if ($project->manager_id) $participantIds->push((int) $project->manager_id);
        foreach ($project->members as $m) {
            $participantIds->push((int) $m->id);
        }
        $participantIds->push((int) $actor->id);
        $participantIds = $participantIds->unique()->values();

        foreach ($participantIds as $userId) {
            ConversationParticipant::firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                ],
                [
                    'role' => ($userId === (int) $project->team_lead_id || $userId === (int) $project->manager_id)
                        ? ConversationParticipant::ROLE_ADMIN
                        : ConversationParticipant::ROLE_MEMBER,
                ]
            );
        }

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
     * Update conversation (group title).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($id);

        if (! $this->accessControl->canManageConversation($actor, $conversation)) {
            return $this->error('You do not have permission to manage this conversation.', 403);
        }

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $conversation->update([
            'title' => $request->input('title'),
        ]);

        return $this->success(
            new ConversationResource($conversation->fresh(['participants.user.role', 'latestMessage.user'])),
            'Conversation updated'
        );
    }

    /**
     * Add participants to group conversation.
     */
    public function addParticipants(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($id);

        if (! $this->accessControl->canManageConversation($actor, $conversation)) {
            return $this->error('You do not have permission to add members.', 403);
        }

        $request->validate([
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $newIds = collect($request->input('participant_ids'))->unique();

        // Verify company
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
            'Participants added'
        );
    }

    /**
     * Remove participant or leave conversation.
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

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->delete();

        return $this->success(null, $isSelf ? 'You left the conversation' : 'Participant removed');
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
