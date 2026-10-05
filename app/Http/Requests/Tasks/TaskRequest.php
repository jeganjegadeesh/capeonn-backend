<?php

namespace App\Http\Requests\Tasks;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('priority') && is_string($this->input('priority'))) {
            $this->merge(['priority' => strtolower(trim($this->input('priority')))]);
        }

        // If creating a subtask via POST /tasks/{task}/subtasks
        $routeTask = $this->route('task');
        if ($this->isMethod('POST') && $routeTask && ! $this->has('parent_task_id')) {
            $taskId = $routeTask instanceof Task ? $routeTask->id : (int) $routeTask;
            if ($taskId) {
                $this->merge(['parent_task_id' => $taskId]);
            }
        }
    }

    public function rules(): array
    {
        $isPost = $this->isMethod('POST');

        return [
            'title' => [$isPost ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => [
                'sometimes', 'string',
                Rule::in([
                    Task::PRIORITY_LOW,
                    Task::PRIORITY_MEDIUM,
                    Task::PRIORITY_HIGH,
                    Task::PRIORITY_URGENT,
                ]),
            ],
            'position' => ['nullable', 'integer', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'assigned_to_id' => [
                'nullable', 'integer', 'exists:users,id',
                function ($attribute, $value, $fail) {
                    if (! $value) {
                        return;
                    }
                    $project = $this->getProject();
                    if (! $project) {
                        return;
                    }
                    $isLead = (int) $project->team_lead_id === (int) $value;
                    $isMember = $project->members()->where('user_id', $value)->exists();
                    if (! $isLead && ! $isMember) {
                        $fail('The assigned user must be an assigned team lead or active member of this project.');
                    }
                },
            ],
            'parent_task_id' => [
                'nullable', 'integer', 'exists:tasks,id',
                function ($attribute, $value, $fail) {
                    if (! $value) {
                        return;
                    }
                    $project = $this->getProject();
                    if (! $project) {
                        return;
                    }
                    $parent = Task::where('project_id', $project->id)->find($value);
                    if (! $parent) {
                        $fail('The selected parent task does not belong to this project.');
                        return;
                    }
                    if ($parent->parent_task_id !== null) {
                        $fail('Nested subtasks beyond one level are not supported.');
                        return;
                    }
                    $isSubtaskRoute = $this->is('*tasks/*/subtasks*');
                    if (! $isSubtaskRoute) {
                        $routeTask = $this->route('task');
                        $taskId = $routeTask instanceof Task ? $routeTask->id : $routeTask;
                        if ($taskId && (int) $value === (int) $taskId) {
                            $fail('A task cannot be its own parent.');
                        }
                    }
                },
            ],
        ];
    }

    public function getProject(): ?Project
    {
        $routeProject = $this->route('project');
        if ($routeProject instanceof Project) {
            return $routeProject;
        }
        if (is_numeric($routeProject)) {
            return Project::find((int) $routeProject);
        }

        $routeTask = $this->route('task');
        if ($routeTask instanceof Task) {
            return $routeTask->project;
        }
        if (is_numeric($routeTask)) {
            return Task::with('project')->find((int) $routeTask)?->project;
        }

        if ($this->has('project_id')) {
            return Project::find((int) $this->input('project_id'));
        }

        return null;
    }
}
