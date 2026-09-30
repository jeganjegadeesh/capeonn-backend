<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        // Only active accounts can reset. The response is identical whether or not
        // the email exists, is deactivated, or was throttled, so it can't be used
        // to find out who has an account.
        Password::sendResetLink([
            'email'     => Str::lower(trim($request->validated('email'))),
            'is_active' => true,
        ]);

        return $this->success(null, 'If that email is registered, a password reset message has been sent.');
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email'                 => Str::lower(trim($request->validated('email'))),
                'password'              => $request->validated('password'),
                'password_confirmation' => $request->input('password_confirmation'),
                'token'                 => $request->validated('token'),
                'is_active'             => true,
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();

                // Anyone holding an old token (including an attacker) is signed out.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Bad token, expired token and unknown email all look the same on purpose.
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return $this->success(null, 'Your password has been reset. You can now log in.');
    }
}
