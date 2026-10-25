<?php

namespace App\Console\Commands;

use App\Mail\MaterialRequestOverdueReminderMail;
use App\Models\MaterialRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('material-requests:overdue-reminder')]
#[Description('Send overdue reminders for issued material requests')]
class MaterialRequestOverdueReminderCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $requests = MaterialRequest::query()
            ->whereNotNull('return_by')
            ->whereIn('status', ['approved', 'issued'])
            ->with('items')
            ->get();

        $count = 0;
        foreach ($requests as $request) {
            if ($request->isOverdue()) {
                Mail::to($request->email)->queue(new MaterialRequestOverdueReminderMail($request));
                $count++;
            }
        }

        $this->info("Sent {$count} overdue reminder(s).");

        return self::SUCCESS;
    }
}
