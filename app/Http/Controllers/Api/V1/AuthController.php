<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuthController extends Controller
{
    private const USER_RELATIONS = ['company', 'department', 'designation', 'role.permissions', 'reportsTo'];

    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', Str::lower(trim($data['email'])))->first();

        // Same message for "no such user" and "wrong password" so emails can't be probed.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $this->auditService->logSecurity(
                    companyId: $user->company_id,
                    userId: $user->id,
                    event: 'auth.failed_login',
                    description: "Failed login attempt for {$data['email']}.",
                    metadata: ['email' => $data['email']],
                    severity: 'warning',
                    request: $request
                );
            }
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            return $this->error('Your account is deactivated. Please contact your administrator.', 403);
        }

        $user->update(['last_login_at' => now()]);

        $this->auditService->logAuth($user, 'auth.login', "User {$user->name} logged in successfully.", $request);

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
        $user = $request->user();

        // Revoke device push token for this device if provided in body or headers
        $deviceToken = $request->input('device_token') ?? $request->header('X-Device-Token');
        if ($deviceToken) {
            \App\Models\DeviceToken::where('user_id', $user->id)
                ->where('token', $deviceToken)
                ->delete();
        }

        // Revokes only the token used for this request (this device).
        $user->currentAccessToken()->delete();

        $this->auditService->logAuth($user, 'auth.logout', "User {$user->name} logged out.", $request);

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

        $this->auditService->logSecurity(
            companyId: $user->company_id,
            userId: $user->id,
            event: 'auth.password_changed',
            description: "User {$user->name} changed password. Other sessions terminated.",
            severity: 'info',
            request: $request
        );

        return $this->success(null, 'Password changed successfully');
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
        ]);

        $user = $request->user();
        $file = $request->file('avatar');

        // Delete existing avatar file if stored on public disk
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('avatars', $filename, 'public');

        $user->update(['avatar_path' => $path]);

        return $this->success(
            (new UserResource($user->fresh(self::USER_RELATIONS)))->resolve(),
            'Avatar uploaded successfully'
        );
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->update(['avatar_path' => null]);

        return $this->success(
            (new UserResource($user->fresh(self::USER_RELATIONS)))->resolve(),
            'Avatar removed successfully'
        );
    }

    public function avatar(int $user): BinaryFileResponse|JsonResponse|RedirectResponse
    {
        $target = User::find($user);
        if (! $target || ! $target->avatar_path) {
            return response()->json(['message' => 'Avatar not found'], 404);
        }

        if (str_starts_with($target->avatar_path, 'http://') || str_starts_with($target->avatar_path, 'https://')) {
            return redirect()->away($target->avatar_path);
        }

        if (Storage::disk('public')->exists($target->avatar_path)) {
            return response()->file(Storage::disk('public')->path($target->avatar_path), [
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        if (Storage::disk('local')->exists($target->avatar_path)) {
            return response()->file(Storage::disk('local')->path($target->avatar_path), [
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        return response()->json(['message' => 'Avatar file not found'], 404);
    }
}
