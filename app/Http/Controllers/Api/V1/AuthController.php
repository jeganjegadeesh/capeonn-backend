<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const USER_RELATIONS = ['company', 'department', 'designation', 'role.permissions', 'reportsTo'];

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', Str::lower(trim($data['email'])))->first();

        // Same message for "no such user" and "wrong password" so emails can't be probed.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            return $this->error('Your account is deactivated. Please contact your administrator.', 403);
        }

        $user->update(['last_login_at' => now()]);

        $minutes   = config('sanctum.expiration');
        $expiresAt = $minutes ? now()->addMinutes((int) $minutes) : null;
        $token     = $user->createToken($data['device_name'] ?? 'unknown-device', ['*'], $expiresAt);

        return $this->success([
            'token'      => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
            'user'       => (new UserResource($user->load(self::USER_RELATIONS)))->resolve(),
        ], 'Login successful');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(self::USER_RELATIONS);

        return $this->success((new UserResource($user))->resolve());
    }

    public function logout(Request $request): JsonResponse
    {
        // Revokes only the token used for this request (this device).
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $request->input('password')]);

        // Sign out every other device; keep the one making this request.
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        return $this->success(null, 'Password changed successfully');
    }
}
