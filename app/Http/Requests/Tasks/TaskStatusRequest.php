<?php

namespace App\Http\Requests\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeTask = $this->route('task');
        $task = $routeTask instanceof Task ? $routeTask : ($routeTask ? Task::find($routeTask) : null);
        $newStatus = $this->input('status');
        $isReopening = $task && $task->status === Task::STATUS_COMPLETED && $newStatus === Task::STATUS_IN_PROGRESS;
        $isChangesRequired = $newStatus === Task::STATUS_CHANGES_REQUIRED;
        $reasonRequired = $isReopening || $isChangesRequired;

        return [
            'status' => [
                'required', 'string',
                Rule::in([
                    Task::STATUS_BACKLOG,
                    Task::STATUS_ASSIGNED,
                    Task::STATUS_IN_PROGRESS,
                    Task::STATUS_REVIEW,
                    Task::STATUS_CHANGES_REQUIRED,
                    Task::STATUS_COMPLETED,
                ]),
            ],
            'reason' => [
                $reasonRequired ? 'required' : 'nullable',
                'string',
                'min:3',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required when requesting changes or reopening a completed task.',
        ];
    }
}
