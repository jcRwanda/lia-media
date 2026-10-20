<?php

namespace App\Services;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Sends the branded welcome email, queueing only when QUEUE_CONNECTION is a real queue. */
class WelcomeMailer
{
    /** Returns false when the transport rejected the message (nothing is ever logged with the password). */
    public function send(User $user, string $temporaryPassword): bool
    {
        $mail = new WelcomeMail($user, $temporaryPassword, url('/login'));

        try {
            if (config('queue.default') === 'sync') {
                Mail::to($user)->send($mail);
            } else {
                Mail::to($user)->queue($mail);
            }
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
