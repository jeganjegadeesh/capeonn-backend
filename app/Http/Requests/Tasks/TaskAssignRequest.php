<?php

namespace App\Http\Requests\Tasks;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

class TaskAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_to_id' => [
                'nullable', 'integer', 'exists:users,id',
                function ($attribute, $value, $fail) {
                    if (! $value) {
                        return;
                    }
                    $routeTask = $this->route('task');
                    $task = $routeTask instanceof Task ? $routeTask : Task::with('project')->find((int) $routeTask);
                    if (! $task || ! $task->project) {
                        return;
                    }
                    $project = $task->project;
                    $isLead = (int) $project->team_lead_id === (int) $value;
                    $isMember = $project->members()->where('user_id', $value)->exists();
                    if (! $isLead && ! $isMember) {
                        $fail('The assigned user must be an assigned team lead or active member of this project.');
                    }
                },
            ],
        ];
    }
}
