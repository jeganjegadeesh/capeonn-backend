<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Chat\MessageReadEvent;
use App\Events\Chat\MessageSentEvent;
use App\Events\Chat\UserTypingEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatMessageController extends Controller
{
    public function __construct(private readonly AccessControl $accessControl)
    {
    }

    /**
     * List messages for a conversation.
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
            ]);

        // Pagination: optionally load messages older than before_id (infinite scroll up)
        if ($beforeId = $request->query('before_id')) {
            $query->where('id', '<', (int) $beforeId);
        }

        $paginator = $query->orderByDesc('id')
            ->paginate($this->perPage($request));

        // Mark as read automatically when fetching latest page
        if (! $beforeId && $paginator->isNotEmpty()) {
            $latestId = $paginator->first()->id;
            ConversationParticipant::where('conversation_id', $conversationId)
                ->where('user_id', $actor->id)
                ->update([
                    'last_read_message_id' => $latestId,
                    'last_read_at' => now(),
                ]);
            broadcast(new MessageReadEvent($conversationId, $actor, $latestId));
        }

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
            return $this->error('You cannot send messages in this conversation.', 403);
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

            // Save attachments if any
            if (! empty($validated['attachments'])) {
                foreach ($validated['attachments'] as $att) {
                    ChatAttachment::create([
                        'chat_message_id' => $msg->id,
                        'file_path' => $att['file_path'],
                        'file_name' => $att['file_name'],
                        'file_size' => (int) $att['file_size'],
                        'mime_type' => $att['mime_type'],
                    ]);
                }
            }

            // Update conversation last_message_at
            $conversation->update(['last_message_at' => now()]);

            // Ensure sender has their last_read updated
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
        ]);

        broadcast(new MessageSentEvent($message));

        return $this->success(
            new ChatMessageResource($message),
            'Message sent',
            201
        );
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

        broadcast(new UserTypingEvent($conversationId, $actor, $isTyping));

        return $this->success(['is_typing' => $isTyping]);
    }

    /**
     * Soft-delete a message.
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

        $message->delete();

        return $this->success(null, 'Message deleted');
    }

    /**
     * Search messages across user's accessible conversations.
     */
    public function search(Request $request): JsonResponse
    {
        $actor = $request->user();
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return $this->error('Search term must be at least 2 characters.', 422);
        }

        $query = ChatMessage::query()
            ->where('message', 'like', "%{$q}%")
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
