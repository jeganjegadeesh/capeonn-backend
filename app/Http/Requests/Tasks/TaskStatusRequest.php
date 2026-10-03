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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
