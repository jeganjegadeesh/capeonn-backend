<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class ProjectFileUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
            ],
            'category' => [
                'nullable',
                'string',
                'in:general,specification,design,document,report,archive',
            ],
            'task_id' => [
                'nullable',
                'integer',
                'exists:tasks,id',
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
