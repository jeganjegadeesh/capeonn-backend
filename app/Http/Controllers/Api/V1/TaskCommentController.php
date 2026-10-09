<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Tasks\TaskCommentCreatedEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskCommentResource;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskCommentController extends Controller
{
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

        $comments = $task->comments()
            ->with([
                'user.role',
                'mentions:id,name',
                'replies.user.role',
                'replies.mentions:id,name',
            ])
            ->get();

        return response()->json([
            'data' => TaskCommentResource::collection($comments),
            'message' => 'Task comments retrieved successfully.',
        ]);
    }

    /**
     * POST /tasks/{task}/comments
     * Post a comment or reply on a task.
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();

        if (! $this->access->canAccessTask($actor, $task)) {
            return response()->json(['message' => 'Unauthorized task access.'], 403);
        }

        $validated = $request->validate([
            'comment'     => ['required', 'string', 'max:10000'],
            'parent_id'   => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'mentions'    => ['nullable', 'array'],
            'mentions.*'  => ['integer'],
        ]);

        if (! empty($validated['parent_id'])) {
            $parent = TaskComment::where('id', $validated['parent_id'])
                ->where('task_id', $task->id)
                ->first();

            if (! $parent) {
                return response()->json([
                    'message' => 'Parent comment does not exist or does not belong to this task.',
                ], 422);
            }
        }

        $comment = DB::transaction(function () use ($validated, $actor, $task) {
            $c = TaskComment::create([
                'company_id'  => $actor->company_id,
                'task_id'     => $task->id,
                'user_id'     => $actor->id,
                'parent_id'   => $validated['parent_id'] ?? null,
                'comment'     => $validated['comment'],
                'attachments' => $validated['attachments'] ?? null,
            ]);

            if (! empty($validated['mentions'])) {
                $mentionIds = User::where('company_id', $actor->company_id)
                    ->where('is_active', true)
                    ->whereIn('id', $validated['mentions'])
                    ->pluck('id')
                    ->all();

                $c->mentions()->sync($mentionIds);
            }

            // Append-only audit activity log
            $task->project->recordActivity(
                action: 'comment_added',
                description: "{$actor->name} commented on task '{$task->title}'",
                userId: $actor->id,
                metadata: [
                    'comment_id' => $c->id,
                    'parent_id'  => $c->parent_id,
                ],
                taskId: $task->id,
            );

            return $c;
        });

        $comment->loadMissing(['user.role', 'mentions:id,name', 'replies.user.role']);

        event(new TaskCommentCreatedEvent($comment, $actor));

        return response()->json([
            'data'    => new TaskCommentResource($comment),
            'message' => 'Comment posted successfully.',
        ], 201);
    }

    /**
     * PUT /tasks/{task}/comments/{comment}
     * Update comment content (author only).
     */
    public function update(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $actor = $request->user();

        if ((int) $comment->task_id !== (int) $task->id) {
            return response()->json(['message' => 'Comment not found on this task.'], 404);
        }

        if ((int) $comment->user_id !== (int) $actor->id) {
            return response()->json(['message' => 'You can only edit your own comments.'], 403);
        }

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:10000'],
        ]);

        $comment->update([
            'comment'   => $validated['comment'],
            'is_edited' => true,
            'edited_at' => now(),
        ]);

        $comment->loadMissing(['user.role', 'mentions:id,name', 'replies.user.role']);

        return response()->json([
            'data'    => new TaskCommentResource($comment),
            'message' => 'Comment updated successfully.',
        ]);
    }

    /**
     * DELETE /tasks/{task}/comments/{comment}
     * Delete comment (author or moderation).
     */
    public function destroy(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $actor = $request->user();

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
            $comment->delete();

            $task->project->recordActivity(
                action: 'comment_deleted',
                description: "A comment on task '{$task->title}' was deleted by {$actor->name}",
                userId: $actor->id,
                metadata: ['comment_id' => $comment->id],
                taskId: $task->id,
            );
        });

        return response()->json([
            'message' => 'Comment deleted successfully.',
        ]);
    }
}
