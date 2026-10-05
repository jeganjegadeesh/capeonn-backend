<?php

namespace App\Http\Requests\Chat;

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
            'reply_to_id' => ['nullable', 'integer', 'exists:chat_messages,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.file_path' => ['required_with:attachments', 'string'],
            'attachments.*.file_name' => ['required_with:attachments', 'string'],
            'attachments.*.file_size' => ['required_with:attachments', 'integer'],
            'attachments.*.mime_type' => ['required_with:attachments', 'string'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $hasMessage = filled(trim((string) $this->input('message')));
            $hasAttachments = is_array($this->input('attachments')) && count($this->input('attachments')) > 0;

            if (! $hasMessage && ! $hasAttachments) {
                $v->errors()->add('message', 'A message text or at least one attachment is required.');
            }
        });
    }
}
