<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The admin page holds the payment keys and the money, so the only way to
 * open it is from the server, for an account that already exists and has
 * verified its email.
 */
#[Signature('app:make-admin {email} {--revoke : Take admin access away instead}')]
#[Description('Let a registered user open the admin page (payment keys and sales), or stop them')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        // Fortify stores emails in lower case.
        $email = Str::lower(trim($this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account uses {$email}. Register on the site first, then run this again.");

            return self::FAILURE;
        }

        $admin = ! $this->option('revoke');

        if ($admin && $user->email_verified_at === null) {
            $this->error("{$email} hasn't verified its email address yet. Open the link in the verification email, then run this again.");

            return self::FAILURE;
        }

        if ($user->is_admin === $admin) {
            $this->info($admin ? "{$email} is already an admin." : "{$email} isn't an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => $admin])->save();

        $this->info($admin ? "{$email} can now open the admin page." : "{$email} can no longer open the admin page.");

        return self::SUCCESS;
    }
}
