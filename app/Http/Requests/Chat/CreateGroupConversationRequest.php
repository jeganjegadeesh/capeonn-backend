<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGroupConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'participant_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                          ->where('is_active', true);
                }),
            ],
            'allow_member_invites' => ['nullable', 'boolean'],
        ];
    }
}
