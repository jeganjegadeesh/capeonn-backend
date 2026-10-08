<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Chat\ChatMessageDeletedEvent;
use App\Events\Chat\ChatMessagePinnedEvent;
use App\Events\Chat\ChatMessageUpdatedEvent;
use App\Events\Chat\MessageReadEvent;
use App\Events\Chat\MessageSentEvent;
use App\Events\Chat\UserTypingEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatMessageMention;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Upload;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatMessageController extends Controller
{
    public function __construct(private readonly AccessControl $accessControl)
    {
    }

    /**
     * List messages for a conversation.
     * Note: Background polling does NOT mark messages as read.
     * Read marking is an explicit user action via POST /read.
     */
    public function index(Request $request, int $conversationId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);

        if (! $this->accessControl->canAccessConversation($actor, $conversation)) {
            return $this->error('You do not have access to this conversation.', 403);
        }

        $query = ChatMessage::where('conversation_id', $conversationId)
            ->with([
                'user:id,name,role_id',
                'user.role:id,name,slug',
                'replyTo.user:id,name',
                'task:id,title,status,priority',
                'attachments',
                'mentions:id,name',
                'pinnedBy:id,name',
                'deletedBy:id,name',
                'conversation.participants',
            ]);

        // Pagination: load messages older than before_id
        if ($beforeId = $request->query('before_id')) {
            $query->where('id', '<', (int) $beforeId);
        }

        $paginator = $query->orderByDesc('id')
            ->paginate($this->perPage($request));

        $items = ChatMessageResource::collection($paginator->items())->resolve();

        return $this->paginated(
            $paginator,
            $items,
            'Messages loaded'
        );
    }

    /**
     * Send a message to a conversation.
     */
    public function store(SendMessageRequest $request, int $conversationId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);

        if (! $this->accessControl->canPostInConversation($actor, $conversation)) {
            return $this->error('You cannot send messages in this conversation. The project may be closed.', 403);
        }

        $validated = $request->validated();

        $message = DB::transaction(function () use ($conversation, $actor, $validated) {
            $type = ChatMessage::TYPE_TEXT;
            if (empty(trim((string) ($validated['message'] ?? ''))) && ! empty($validated['attachments'])) {
                $type = ChatMessage::TYPE_FILE;
            }

            $msg = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $actor->id,
                'message' => $validated['message'] ?? null,
                'type' => $type,
                'reply_to_id' => $validated['reply_to_id'] ?? null,
                'task_id' => $validated['task_id'] ?? null,
            ]);

            // Save attachments verified from server upload records
            if (! empty($validated['attachments'])) {
                foreach ($validated['attachments'] as $att) {
                    $uploadId = $att['upload_id'] ?? null;
                    $filePath = $att['file_path'] ?? null;

                    $upload = null;
                    if ($uploadId) {
                        $upload = Upload::where('company_id', $actor->company_id)
                            ->where('user_id', $actor->id)
                            ->where('id', (int) $uploadId)
                            ->first();
                    }

                    if ($upload) {
                        ChatAttachment::create([
                            'chat_message_id' => $msg->id,
                            'file_path' => $upload->file_path,
                            'file_name' => $upload->file_name,
                            'file_size' => $upload->file_size,
                            'mime_type' => $upload->mime_type,
                        ]);
                    }
                }
            }

            // Save @mentions strictly from validated client-provided mention IDs
            $mentionIds = collect($validated['mentions'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->filter(fn ($id) => $id !== (int) $actor->id);

            foreach ($mentionIds as $mUserId) {
                ChatMessageMention::firstOrCreate([
                    'chat_message_id' => $msg->id,
                    'user_id' => (int) $mUserId,
                ]);
            }

            // Update conversation last_message_at
            $conversation->update(['last_message_at' => now()]);

            // Update sender's last_read
            ConversationParticipant::updateOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $actor->id,
                ],
                [
                    'last_read_message_id' => $msg->id,
                    'last_read_at' => now(),
                ]
            );

            return $msg;
        });

        $message->loadMissing([
            'user:id,name,role_id',
            'user.role:id,name,slug',
            'replyTo.user:id,name',
            'task:id,title,status,priority',
            'attachments',
            'mentions:id,name',
            'pinnedBy:id,name',
            'conversation.participants',
        ]);

        broadcast(new MessageSentEvent($message));

        return $this->success(
            new ChatMessageResource($message),
            'Message sent',
            201
        );
    }

    /**
     * Edit a chat message.
     */
    public function update(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);
        $message = ChatMessage::where('conversation_id', $conversationId)->findOrFail($messageId);

        if ((int) $message->user_id !== (int) $actor->id) {
            return $this->error('You can only edit your own messages.', 403);
        }

        if ($message->trashed()) {
            return $this->error('Cannot edit a deleted message.', 422);
        }

        if (! $this->accessControl->canPostInConversation($actor, $conversation)) {
            return $this->error('You cannot edit messages in this conversation. The project may be closed or access revoked.', 403);
        }

        if ($message->created_at && $message->created_at->diffInHours(now()) > 24) {
            return $this->error('Messages can only be edited within 24 hours of sending.', 422);
        }

        $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ]);

        $message->update([
            'message' => $request->input('message'),
            'is_edited' => true,
            'edited_at' => now(),
        ]);

        if ($request->has('mentions')) {
            $newMentions = collect($request->input('mentions', []))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->filter(fn ($id) => $id !== (int) $actor->id);

            $participantIds = $conversation->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            foreach ($newMentions as $mId) {
                if (! in_array($mId, $participantIds, true)) {
                    return $this->error('Mentioned users must be participants in this conversation.', 422);
                }
            }

            ChatMessageMention::where('chat_message_id', $message->id)->delete();
            foreach ($newMentions as $mId) {
                ChatMessageMention::create([
                    'chat_message_id' => $message->id,
                    'user_id' => $mId,
                ]);
            }
        }

        $message->loadMissing([
            'user:id,name,role_id',
            'user.role:id,name,slug',
            'replyTo.user:id,name',
            'task:id,title,status,priority',
            'attachments',
            'mentions:id,name',
            'pinnedBy:id,name',
            'conversation.participants',
        ]);

        broadcast(new ChatMessageUpdatedEvent($message));

        return $this->success(
            new ChatMessageResource($message),
            'Message updated successfully'
        );
    }

    /**
     * Soft-delete a message and broadcast deletion event.
     */
    public function destroy(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);
        $message = ChatMessage::where('conversation_id', $conversationId)->findOrFail($messageId);

        $isAuthor = (int) $message->user_id === (int) $actor->id;
        $canManage = $this->accessControl->canManageConversation($actor, $conversation);

        if (! $isAuthor && ! $canManage) {
            return $this->error('You cannot delete this message.', 403);
        }

        $message->update(['deleted_by_id' => $actor->id]);
        $message->delete();

        broadcast(new ChatMessageDeletedEvent($conversationId, $messageId, $actor->id));

        return $this->success(null, 'Message deleted');
    }

    /**
     * Pin or unpin a message in the conversation.
     */
    public function pin(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);
        $message = ChatMessage::where('conversation_id', $conversationId)->findOrFail($messageId);

        if (! $this->accessControl->canAccessConversation($actor, $conversation)) {
            return $this->error('You do not have access to pin messages here.', 403);
        }

        $canPin = false;
        if ($conversation->isDirect()) {
            $canPin = $conversation->participants()->where('user_id', $actor->id)->exists();
        } elseif ($conversation->isGroup()) {
            $canPin = $this->accessControl->canManageConversation($actor, $conversation);
        } elseif ($conversation->isProject()) {
            $project = $conversation->project;
            $canPin = $actor->hasRole(Role::SUPER_ADMIN, 'admin')
                || ($project && ($actor->id === $project->team_lead_id || $this->accessControl->canManageProject($actor, $project)));
        }

        if (! $canPin) {
            return $this->error('You do not have permission to pin messages in this conversation.', 403);
        }

        $isPinned = ! $message->is_pinned;

        if ($isPinned) {
            $pinnedCount = ChatMessage::where('conversation_id', $conversationId)->where('is_pinned', true)->count();
            if ($pinnedCount >= 5) {
                return $this->error('A maximum of 5 messages can be pinned per conversation.', 422);
            }
        }

        $message->update([
            'is_pinned' => $isPinned,
            'pinned_at' => $isPinned ? now() : null,
            'pinned_by_id' => $isPinned ? $actor->id : null,
        ]);

        $message->loadMissing([
            'user:id,name,role_id',
            'user.role:id,name,slug',
            'replyTo.user:id,name',
            'task:id,title,status,priority',
            'attachments',
            'mentions:id,name',
            'pinnedBy:id,name',
            'conversation.participants',
        ]);

        broadcast(new ChatMessagePinnedEvent($message, $isPinned));

        return $this->success(
            new ChatMessageResource($message),
            $isPinned ? 'Message pinned' : 'Message unpinned'
        );
    }

    /**
     * Authorized download or preview of a chat attachment.
     */
    public function downloadAttachment(Request $request, int $conversationId, int $attachmentId): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);

        if (! $this->accessControl->canAccessConversation($actor, $conversation)) {
            return $this->error('You do not have permission to view attachments from this conversation.', 403);
        }

        $attachment = ChatAttachment::whereHas('message', fn ($q) => $q->where('conversation_id', $conversationId))
            ->findOrFail($attachmentId);

        $disk = Storage::disk('local');
        if (! $disk->exists($attachment->file_path)) {
            return $this->error('Attachment file not found on disk.', 404);
        }

        if ($request->boolean('preview') || $request->query('inline')) {
            $headers = [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . addslashes($attachment->file_name) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ];
            return $disk->response($attachment->file_path, $attachment->file_name, $headers);
        }

        return $disk->download($attachment->file_path, $attachment->file_name);
    }

    /**
     * Mark all messages up to last_read_message_id as read.
     */
    public function markAsRead(Request $request, int $conversationId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);

        if (! $this->accessControl->canAccessConversation($actor, $conversation)) {
            return $this->error('You do not have access to this conversation.', 403);
        }

        $lastMessageId = $request->input('last_read_message_id')
            ?? ChatMessage::where('conversation_id', $conversationId)->max('id');

        if ($lastMessageId) {
            ConversationParticipant::where('conversation_id', $conversationId)
                ->where('user_id', $actor->id)
                ->update([
                    'last_read_message_id' => (int) $lastMessageId,
                    'last_read_at' => now(),
                ]);

            broadcast(new MessageReadEvent($conversationId, $actor, (int) $lastMessageId));
        }

        return $this->success([
            'last_read_message_id' => (int) $lastMessageId,
        ], 'Marked as read');
    }

    /**
     * Broadcast typing status.
     */
    public function typing(Request $request, int $conversationId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);

        if (! $this->accessControl->canAccessConversation($actor, $conversation)) {
            return $this->error('You do not have access to this conversation.', 403);
        }

        $isTyping = (bool) $request->input('is_typing', true);

        broadcast(new UserTypingEvent($conversationId, $actor, $isTyping))->toOthers();

        // Cache-backed typing status for dual-mode polling fallback with atomic concurrency handling
        $cacheKey = "conv_typing_{$conversationId}";
        try {
            $lock = \Illuminate\Support\Facades\Cache::lock("lock_{$cacheKey}", 3);
            $lock->block(2, function () use ($cacheKey, $actor, $isTyping) {
                $typingList = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
                if (! is_array($typingList)) {
                    $typingList = [];
                }

                if ($isTyping) {
                    $typingList[$actor->id] = [
                        'user_id' => $actor->id,
                        'user_name' => $actor->name,
                        'updated_at' => now()->timestamp,
                    ];
                } else {
                    unset($typingList[$actor->id]);
                }

                $now = now()->timestamp;
                $typingList = array_filter($typingList, fn ($item) => ($item['updated_at'] ?? 0) >= $now - 5);
                \Illuminate\Support\Facades\Cache::put($cacheKey, $typingList, now()->addSeconds(6));
            });
        } catch (\Throwable $e) {
            $typingList = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
            if (! is_array($typingList)) $typingList = [];
            if ($isTyping) {
                $typingList[$actor->id] = [
                    'user_id' => $actor->id,
                    'user_name' => $actor->name,
                    'updated_at' => now()->timestamp,
                ];
            } else {
                unset($typingList[$actor->id]);
            }
            \Illuminate\Support\Facades\Cache::put($cacheKey, $typingList, now()->addSeconds(6));
        }

        return $this->success(['is_typing' => $isTyping]);
    }

    /**
     * Get active partner typing status (HTTP polling fallback).
     */
    public function getTyping(Request $request, int $conversationId): JsonResponse
    {
        $actor = $request->user();

        $conversation = Conversation::where('company_id', $actor->company_id)->findOrFail($conversationId);

        if (! $this->accessControl->canAccessConversation($actor, $conversation)) {
            return $this->error('You do not have access to this conversation.', 403);
        }

        $cacheKey = "conv_typing_{$conversationId}";
        $typingList = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
        if (! is_array($typingList)) {
            $typingList = [];
        }

        $now = now()->timestamp;
        $activeOtherTyping = null;
        foreach ($typingList as $userId => $item) {
            if ((int) $userId !== (int) $actor->id && ($item['updated_at'] ?? 0) >= $now - 4) {
                $activeOtherTyping = $item;
                break;
            }
        }

        if ($activeOtherTyping) {
            return $this->success([
                'conversation_id' => $conversationId,
                'user_id' => $activeOtherTyping['user_id'],
                'user_name' => $activeOtherTyping['user_name'],
                'is_typing' => true,
            ]);
        }

        return $this->success([
            'conversation_id' => $conversationId,
            'is_typing' => false,
        ]);
    }

    /**
     * Search messages across user's accessible conversations with wildcard escaping and date limit.
     */
    public function search(Request $request): JsonResponse
    {
        $actor = $request->user();
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return $this->error('Search term must be at least 2 characters.', 422);
        }

        $escaped = addcslashes($q, '%_\\');

        $query = ChatMessage::query()
            ->whereNull('deleted_at')
            ->where('message', 'like', "%{$escaped}%")
            ->whereHas('conversation', function ($convQuery) use ($actor) {
                $this->accessControl->constrainConversations($convQuery, $actor);
            })
            ->with([
                'user:id,name,role_id',
                'user.role:id,name,slug',
                'conversation:id,type,title,project_id',
                'task:id,title',
            ]);

        $paginator = $query->orderByDesc('id')
            ->paginate($this->perPage($request));

        $items = ChatMessageResource::collection($paginator->items())->resolve();

        return $this->paginated(
            $paginator,
            $items,
            'Search results'
        );
    }
}
