<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'       => ['required', 'string', 'email'],
            'password'    => ['required', 'string'],
            // Shown later in the "active sessions" list, e.g. "Pixel 8 / Android".
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
