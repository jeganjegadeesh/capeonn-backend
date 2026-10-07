<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDirectConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $currentId = $this->user()?->id;

        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::notIn([$currentId]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.not_in' => 'You cannot start a direct conversation with yourself.',
            'user_id.exists' => 'The selected colleague does not exist, is inactive, or is not in your company.',
        ];
    }
}
