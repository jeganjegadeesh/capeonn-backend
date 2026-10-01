<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:150'],
            'document_type' => ['required', 'string', 'in:resume,id_proof,contract,certificate,educational,other'],
            'file'          => ['nullable', 'file', 'max:20480'], // max 20MB
            'file_path'     => ['nullable', 'string', 'max:255'],
            'file_name'     => ['nullable', 'string', 'max:255'],
            'file_size'     => ['nullable', 'integer'],
            'mime_type'     => ['nullable', 'string', 'max:100'],
        ];
    }
}
