<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\WelcomeMailer;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit, private WelcomeMailer $mailer) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()->orderBy('name')->get()->map->toApi()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(Roles::ALL)],
        ]);
        $temporaryPassword = Str::password(20);
        $user = User::query()->create([
            ...$data,
            'email' => strtolower($data['email']),
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ]);
        $sent = $this->mailer->send($user, $temporaryPassword);
        $this->audit->log('user.created', $user, ['role' => $user->role, 'welcome_email_sent' => $sent]);

        return response()->json(['user' => $user->toApi(), 'welcome_email_sent' => $sent], 201);
    }

    /** Generates a fresh temporary password, locks the account until it is changed, and re-sends the welcome email. */
    public function resendWelcome(Request $request, User $user): JsonResponse
    {
        $temporaryPassword = Str::password(20);
        $user->update(['password' => $temporaryPassword, 'must_change_password' => true]);
        $sent = $this->mailer->send($user, $temporaryPassword);
        $this->audit->log('user.welcome_resent', $user, ['welcome_email_sent' => $sent]);

        return response()->json(['user' => $user->toApi(), 'welcome_email_sent' => $sent]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(Roles::ALL)],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]);
        if ($user->is($request->user()) && ((isset($data['role']) && $data['role'] !== Roles::ADMIN) || ($data['status'] ?? 'active') !== 'active')) {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own admin access.']);
        }
        $before = $user->only(array_keys($data));
        $user->update($data);
        $this->audit->log('user.updated', $user, ['before' => $before, 'after' => $user->only(array_keys($data))]);

        return response()->json(['user' => $user->toApi()]);
    }
}
