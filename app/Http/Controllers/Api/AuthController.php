<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
        $this->audit->log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

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
}
