<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Tasks\TaskCommentCreatedEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskCommentResource;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskCommentAttachment;
use App\Models\TaskCommentEdit;
use App\Models\Upload;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskCommentController extends Controller
{
    private const WITH = [
        'user.role',
        'mentions:id,name',
        'attachmentFiles',
        'edits',
        'replies.user.role',
        'replies.mentions:id,name',
        'replies.attachmentFiles',
        'replies.edits',
    ];

    public function __construct(private AccessControl $access)
    {
    }

    /**
     * GET /tasks/{task}/comments
     * List all root comments with nested replies for a task.
     */
    public function index(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        $commentsQuery = $task->comments()
            ->with(self::WITH)
            ->orderBy('id', 'asc');

        if ($request->has('per_page') || $request->has('page')) {
            $perPage = min(max((int) $request->query('per_page', 30), 1), 100);
            $paginator = $commentsQuery->paginate($perPage);

            return response()->json([
                'data' => TaskCommentResource::collection($paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                ],
                'message' => 'Task comments retrieved successfully.',
            ]);
        }

        $comments = $commentsQuery->get();

        return response()->json([
            'data'    => TaskCommentResource::collection($comments),
            'message' => 'Task comments retrieved successfully.',
        ]);
    }

    /**
     * POST /tasks/{task}/comments
     * Post a comment or reply on a task with verified attachments and mention validation.
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => 'Project status does not accept modifications.',
            ], 422);
        }

        $validated = $request->validate([
            'comment'      => ['required', 'string', 'max:10000'],
            'parent_id'    => ['nullable', 'integer'],
            'upload_ids'   => ['nullable', 'array', 'max:10'],
            'upload_ids.*' => ['integer'],
            'attachments'  => ['nullable', 'array', 'max:10'],
            'mentions'     => ['nullable', 'array'],
            'mentions.*'   => ['integer'],
        ]);

        // Reply depth check: Replies only allowed to top-level comments
        if (! empty($validated['parent_id'])) {
            $parent = TaskComment::where('id', $validated['parent_id'])
                ->where('task_id', $task->id)
                ->first();

            if (! $parent) {
                return response()->json([
                    'message' => 'Parent comment does not exist or does not belong to this task.',
                ], 422);
            }

            if ($parent->parent_id !== null) {
                return response()->json([
                    'message' => 'Replies can only be added to top-level comments.',
                ], 422);
            }
        }

        // Validate verified attachments (upload_ids)
        $verifiedUploads = collect();
        $uploadIds = $validated['upload_ids'] ?? [];
        if (empty($uploadIds) && ! empty($validated['attachments'])) {
            // Support attachments array containing upload IDs or ints
            $uploadIds = array_filter(array_map('intval', array_filter($validated['attachments'], 'is_numeric')));
        }

        if (! empty($uploadIds)) {
            $verifiedUploads = Upload::where('company_id', $actor->company_id)
                ->where('user_id', $actor->id)
                ->whereIn('id', $uploadIds)
                ->get();

            if ($verifiedUploads->count() !== count(array_unique($uploadIds))) {
                return response()->json([
                    'message' => 'One or more attachment uploads are invalid or not found.',
                ], 422);
            }
        }

        // Validate mentions: Every mentioned user must have access to the task!
        $validMentionUsers = collect();
        if (! empty($validated['mentions'])) {
            $validMentionUsers = User::where('company_id', $actor->company_id)
                ->where('is_active', true)
                ->whereIn('id', $validated['mentions'])
                ->get();

            foreach ($validMentionUsers as $mUser) {
                if (! $this->access->canAccessTask($mUser, $task)) {
                    return response()->json([
                        'message' => "Mentioned user '{$mUser->name}' does not have access to this task.",
                    ], 422);
                }
            }
        }

        $comment = DB::transaction(function () use ($validated, $actor, $task, $verifiedUploads, $validMentionUsers) {
            $c = TaskComment::create([
                'company_id'  => $actor->company_id,
                'task_id'     => $task->id,
                'user_id'     => $actor->id,
                'parent_id'   => $validated['parent_id'] ?? null,
                'comment'     => $validated['comment'],
            ]);

            // Save verified attachments
            foreach ($verifiedUploads as $upload) {
                TaskCommentAttachment::create([
                    'company_id'      => $actor->company_id,
                    'task_comment_id' => $c->id,
                    'upload_id'       => $upload->id,
                    'file_name'       => $upload->file_name,
                    'file_path'       => $upload->file_path,
                    'file_size'       => $upload->file_size,
                    'mime_type'       => $upload->mime_type,
                    'disk'            => $upload->disk ?? 'local',
                ]);
            }

            // Sync mentions
            if ($validMentionUsers->isNotEmpty()) {
                $c->mentions()->sync($validMentionUsers->pluck('id')->all());
            }

            // Append-only audit activity log
            $task->project->recordActivity(
                action: 'comment_added',
                description: "{$actor->name} commented on task '{$task->title}'",
                userId: $actor->id,
                metadata: [
                    'comment_id'        => $c->id,
                    'parent_id'         => $c->parent_id,
                    'attachments_count' => $verifiedUploads->count(),
                ],
                taskId: $task->id,
            );

            // Dispatch event after commit
            DB::afterCommit(function () use ($c, $actor) {
                event(new TaskCommentCreatedEvent($c, $actor));
            });

            return $c;
        });

        $comment->loadMissing(self::WITH);

        return response()->json([
            'data'    => new TaskCommentResource($comment),
            'message' => 'Comment posted successfully.',
        ], 201);
    }

    /**
     * PUT /tasks/{task}/comments/{comment}
     * Update comment content with revision history and access validation.
     */
    public function update(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => 'Project status does not accept modifications.',
            ], 422);
        }

        if ((int) $comment->task_id !== (int) $task->id) {
            return response()->json(['message' => 'Comment not found on this task.'], 404);
        }

        if ((int) $comment->user_id !== (int) $actor->id) {
            return response()->json(['message' => 'You can only edit your own comments.'], 403);
        }

        $validated = $request->validate([
            'comment'    => ['required', 'string', 'max:10000'],
            'mentions'   => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ]);

        // Validate mentions
        $validMentionUsers = collect();
        if (isset($validated['mentions'])) {
            $validMentionUsers = User::where('company_id', $actor->company_id)
                ->where('is_active', true)
                ->whereIn('id', $validated['mentions'])
                ->get();

            foreach ($validMentionUsers as $mUser) {
                if (! $this->access->canAccessTask($mUser, $task)) {
                    return response()->json([
                        'message' => "Mentioned user '{$mUser->name}' does not have access to this task.",
                    ], 422);
                }
            }
        }

        $oldComment = $comment->comment;
        $newComment = $validated['comment'];

        DB::transaction(function () use ($comment, $task, $actor, $oldComment, $newComment, $validMentionUsers, $validated) {
            if ($oldComment !== $newComment) {
                // Record revision history
                TaskCommentEdit::create([
                    'company_id'      => $actor->company_id,
                    'task_comment_id' => $comment->id,
                    'user_id'         => $actor->id,
                    'old_comment'     => $oldComment,
                    'new_comment'     => $newComment,
                    'created_at'      => now(),
                ]);

                $comment->update([
                    'comment'   => $newComment,
                    'is_edited' => true,
                    'edited_at' => now(),
                ]);

                $task->project->recordActivity(
                    action: 'comment_edited',
                    description: "{$actor->name} edited a comment on task '{$task->title}'",
                    userId: $actor->id,
                    oldValue: $oldComment,
                    newValue: $newComment,
                    metadata: ['comment_id' => $comment->id],
                    taskId: $task->id,
                );
            }

            if (isset($validated['mentions'])) {
                $existingMentionIds = $comment->mentions()->pluck('users.id')->all();
                $newMentionIds = $validMentionUsers->pluck('id')->all();
                $addedIds = array_diff($newMentionIds, $existingMentionIds);

                $comment->mentions()->sync($newMentionIds);

                if (! empty($addedIds)) {
                    DB::afterCommit(function () use ($comment, $actor) {
                        event(new TaskCommentCreatedEvent($comment, $actor));
                    });
                }
            }
        });

        $comment->loadMissing(self::WITH);

        return response()->json([
            'data'    => new TaskCommentResource($comment),
            'message' => 'Comment updated successfully.',
        ]);
    }

    /**
     * DELETE /tasks/{task}/comments/{comment}
     * Delete comment with access check and cascade awareness.
     */
    public function destroy(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if (! $task->project->acceptsWork()) {
            return response()->json([
                'message' => 'Project status does not accept modifications.',
            ], 422);
        }

        if ((int) $comment->task_id !== (int) $task->id) {
            return response()->json(['message' => 'Comment not found on this task.'], 404);
        }

        $isAuthor = (int) $comment->user_id === (int) $actor->id;
        $isAdmin = $actor->hasRole(Role::SUPER_ADMIN, 'admin');
        $isLead = (int) $task->project->team_lead_id === (int) $actor->id;
        $isManager = $this->access->canManageProject($actor, $task->project);

        if (! ($isAuthor || $isAdmin || $isLead || $isManager)) {
            return response()->json(['message' => 'You do not have permission to delete this comment.'], 403);
        }

        DB::transaction(function () use ($comment, $task, $actor) {
            $commentId = $comment->id;
            $comment->delete();

            $task->project->recordActivity(
                action: 'comment_deleted',
                description: "A comment on task '{$task->title}' was deleted by {$actor->name}",
                userId: $actor->id,
                metadata: ['comment_id' => $commentId],
                taskId: $task->id,
            );
        });

        return response()->json([
            'message' => 'Comment deleted successfully.',
        ]);
    }

    /**
     * GET /tasks/{task}/comments/{comment}/history
     * Retrieve revision history of a comment.
     */
    public function history(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if ((int) $comment->task_id !== (int) $task->id) {
            return response()->json(['message' => 'Comment not found on this task.'], 404);
        }

        $edits = $comment->edits()
            ->with('user:id,name,email,avatar_url')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'data' => $edits->map(fn ($e) => [
                'id'          => $e->id,
                'old_comment' => $e->old_comment,
                'new_comment' => $e->new_comment,
                'created_at'  => $e->created_at?->toIso8601String(),
                'user'        => $e->user ? [
                    'id'         => $e->user->id,
                    'name'       => $e->user->name,
                    'avatar_url' => $e->user->avatar_url,
                ] : null,
            ]),
            'message' => 'Comment edit history retrieved successfully.',
        ]);
    }

    /**
     * GET /tasks/{task}/comments/{comment}/attachments/{attachment}/download
     * Authorized download / preview for comment attachments.
     */
    public function downloadAttachment(
        Request $request,
        Task $task,
        TaskComment $comment,
        TaskCommentAttachment $attachment,
    ): mixed {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        if ((int) $attachment->task_comment_id !== (int) $comment->id || (int) $comment->task_id !== (int) $task->id) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->file_path)) {
            return response()->json(['message' => 'Attachment file not found on server.'], 404);
        }

        if ($request->boolean('preview') && ($attachment->is_image || $attachment->is_pdf)) {
            return response()->file($disk->path($attachment->file_path), [
                'Content-Type' => $attachment->mime_type,
            ]);
        }

        return $disk->download($attachment->file_path, $attachment->file_name);
    }
}
