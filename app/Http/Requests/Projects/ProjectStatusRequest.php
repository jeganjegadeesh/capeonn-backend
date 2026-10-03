<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('project');
        $project = is_numeric($projectId) ? Project::find($projectId) : ($projectId instanceof Project ? $projectId : null);

        return [
            'status' => [
                'required', 'string',
                Rule::in([
                    Project::STATUS_PLANNED,
                    Project::STATUS_ACTIVE,
                    Project::STATUS_ON_HOLD,
                    Project::STATUS_COMPLETED,
                    Project::STATUS_ARCHIVED,
                    Project::STATUS_CANCELLED,
                ]),
            ],
            'reason' => [
                'nullable', 'string', 'max:1000',
                Rule::requiredIf(function () use ($project) {
                    $newStatus = $this->input('status');
                    if (in_array($newStatus, [Project::STATUS_ON_HOLD, Project::STATUS_CANCELLED], true)) {
                        return true;
                    }
                    if ($project && in_array($project->status, [Project::STATUS_ON_HOLD, Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED], true) && $newStatus === Project::STATUS_ACTIVE) {
                        return true;
                    }
                    return false;
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required when placing a project on hold, cancelling, or reopening it.',
        ];
    }
}
