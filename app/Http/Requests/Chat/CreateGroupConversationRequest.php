<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
