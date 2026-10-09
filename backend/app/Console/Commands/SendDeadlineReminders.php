<?php

namespace App\Console\Commands;

use App\Mail\DeadlineReminder;
use App\Models\User;
use App\Services\DeadlineService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('deadlines:remind {--days=5 : Remind about deadlines due within this many days}')]
#[Description('Email users about lodge and escalation deadlines due soon')]
class SendDeadlineReminders extends Command
{
    public function handle(DeadlineService $deadlines): int
    {
        $days = max(0, (int) $this->option('days'));
        $sent = 0;

        User::query()->orderBy('id')->chunkById(100, function ($users) use ($deadlines, $days, &$sent) {
            foreach ($users as $user) {
                $due = $deadlines->dueWithin($user, $days);
                if ($due->isEmpty()) {
                    continue;
                }

                Mail::to($user)->send(new DeadlineReminder($user, $due->all()));
                $sent++;
            }
        });

        $this->info("Sent $sent reminder email(s).");

        return self::SUCCESS;
    }
}
