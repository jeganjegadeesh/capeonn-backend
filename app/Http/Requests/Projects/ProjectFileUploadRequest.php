<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectFileUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $projectId = (int) $this->route('project');
        $companyId = $this->user()?->company_id;

        return [
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,tar,gz',
            ],
            'category' => [
                'nullable',
                'string',
                'in:general,specification,design,document,report,archive',
            ],
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where(function ($query) use ($projectId, $companyId) {
                    $query->where('project_id', $projectId)
                          ->where('company_id', $companyId);
                }),
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'task_id.exists' => 'The selected task does not exist or does not belong to this project.',
        ];
    }
}
