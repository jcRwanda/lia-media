<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Storage\ObjectStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException; 

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::query()->where('email', strtolower($data['email']))->first();
        if (! $user || ! $user->isActive() || ! Auth::guard('web')->attempt(['email' => $user->email, 'password' => $data['password']])) {
            $this->audit->log('auth.login_failed', $user, ['email' => strtolower($data['email'])], null, 'system');
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user, [], $user);

        return response()->json(['user' => $user->toApi()]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()) {
            $this->audit->log('auth.logout', $request->user());
        }
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toApi()]);
    }

    /** Replaces the admin-generated temporary password and lifts the first-login lock. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'password' => [
                'required', 'string', 'min:10', 'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if (is_string($value) && Hash::check($value, $user->password)) {
                        $fail('The new password must be different from your current password.');
                    }
                },
            ],
        ]);
        $user->update(['password' => $data['password'], 'must_change_password' => false]);
        $this->audit->log('auth.password_changed', $user);

        return response()->json(['user' => $user->toApi()]);
    }

    public function changePasswordWithCurrent(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required', 'string', 'min:10', 'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if (is_string($value) && Hash::check($value, $user->password)) {
                        $fail('The new password must be different from your current password.');
                    }
                },
            ],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => $data['password']]);
        $this->audit->log('auth.password_changed', $user);

        return response()->json(['user' => $user->toApi()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', function (string $attr, mixed $value, \Closure $fail) {
                if (trim((string) $value) !== '' && \App\Support\Phone::normalize((string) $value) === null) {
                    $fail('Enter a valid phone/WhatsApp number, e.g. 0788123456 or +250788123456.');
                }
            }],
        ]);
        if (array_key_exists('phone', $data)) {
            $data['phone'] = \App\Support\Phone::normalize($data['phone']);
        }
        $user->fill($data);
        $user->save();
        $this->audit->log('user.profile_updated', $user);

        return response()->json(['user' => $user->toApi()]);
    }

    public function uploadPhoto(Request $request, ObjectStorage $storage): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'filename' => ['sometimes', 'string'],
            'content_type' => ['sometimes', 'string'],
            'photo' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp,gif,heic,heif', 'max:20480'],
        ]);

        if ($request->hasFile('photo')) {
            $key = \App\Support\ImageNormalizer::store($request->file('photo'), 'profile-photos/'.$user->id, $storage)['path'];
            $user->forceFill(['photo_path' => $key])->save();
            $url = method_exists($storage, 'temporaryUrl') ? $storage->temporaryUrl($key, now()->addMinutes(60)) : null;
            return response()->json(['photo_url' => $url, 'user' => $user->toApi()]);
        }

        // Generate presigned upload URL if supported
        $filename = $request->input('filename') ?: 'photo.jpg';
        $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg';
        $key = 'profile-photos/'.$user->id.'/'.Str::uuid().'.'.$ext;
        try {
            if (method_exists($storage, 'presignedPut')) {
                $presigned = $storage->presignedPut($key, $request->input('content_type') ?: 'image/jpeg', now()->addMinutes(10));
                $user->forceFill(['photo_path' => $key])->save();
                return response()->json(['upload_url' => $presigned->url ?? ($presigned['url'] ?? null), 'headers' => $presigned->headers ?? ($presigned['headers'] ?? []), 'key' => $key, 'photo_url' => method_exists($storage, 'temporaryUrl') ? $storage->temporaryUrl($key, now()->addMinutes(60)) : null]);
            }
        } catch (\Throwable $e) {
            // fall through
        }
        $user->forceFill(['photo_path' => $key])->save();
        $url = method_exists($storage, 'temporaryUrl') ? $storage->temporaryUrl($key, now()->addMinutes(60)) : null;
        return response()->json(['photo_url' => $url, 'key' => $key]);
    }
}
