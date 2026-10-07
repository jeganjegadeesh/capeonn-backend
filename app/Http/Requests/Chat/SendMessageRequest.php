<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer'],
            'task_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.upload_id' => ['nullable', 'integer'],
            'attachments.*.file_path' => ['nullable', 'string'],
            'attachments.*.file_name' => ['nullable', 'string'],
            'attachments.*.file_size' => ['nullable', 'integer'],
            'attachments.*.mime_type' => ['nullable', 'string'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $actor = $this->user();
            if (! $actor) return;

            $convId = (int) $this->route('conversation');
            $conversation = Conversation::find($convId);

            $hasMessage = filled(trim((string) $this->input('message')));
            $attachments = $this->input('attachments');
            $hasAttachments = is_array($attachments) && count($attachments) > 0;

            if (! $hasMessage && ! $hasAttachments) {
                $v->errors()->add('message', 'A message text or at least one attachment is required.');
                return;
            }

            // 1. Cross-chat validation: reply_to_id must belong to this conversation
            if ($replyToId = $this->input('reply_to_id')) {
                $replyMsg = ChatMessage::where('id', $replyToId)
                    ->where('conversation_id', $convId)
                    ->whereNull('deleted_at')
                    ->first();

                if (! $replyMsg) {
                    $v->errors()->add('reply_to_id', 'The reply message does not exist or does not belong to this conversation.');
                }
            }

            // 2. Cross-company and cross-project validation: task_id
            if ($taskId = $this->input('task_id')) {
                $task = Task::where('id', $taskId)
                    ->where('company_id', $actor->company_id)
                    ->first();

                if (! $task) {
                    $v->errors()->add('task_id', 'The selected task does not exist or does not belong to your company.');
                } elseif ($conversation && $conversation->isProject() && (int) $task->project_id !== (int) $conversation->project_id) {
                    $v->errors()->add('task_id', 'The selected task does not belong to this project.');
                }
            }

            // 3. Server verification of attachments
            if ($hasAttachments) {
                foreach ($attachments as $index => $att) {
                    $uploadId = $att['upload_id'] ?? null;
                    $filePath = $att['file_path'] ?? null;

                    $query = Upload::where('company_id', $actor->company_id)
                        ->where('user_id', $actor->id);

                    if ($uploadId) {
                        $query->where('id', (int) $uploadId);
                    } elseif ($filePath) {
                        $query->where('file_path', $filePath);
                    } else {
                        $v->errors()->add("attachments.{$index}", 'Attachment must specify upload_id or file_path.');
                        continue;
                    }

                    $upload = $query->first();
                    if (! $upload) {
                        $v->errors()->add("attachments.{$index}", 'Attachment path or ID is invalid or was not uploaded by you.');
                    }
                }
            }

            // 4. Validate mentions belong to company
            if ($mentions = $this->input('mentions')) {
                $validCount = User::where('company_id', $actor->company_id)
                    ->where('is_active', true)
                    ->whereIn('id', $mentions)
                    ->count();

                if ($validCount !== count(array_unique($mentions))) {
                    $v->errors()->add('mentions', 'One or more mentioned users are invalid or not in your company.');
                }
            }
        });
    }
}
