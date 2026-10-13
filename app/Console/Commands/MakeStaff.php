<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Staff see every customer's bills and disputes, so the only way to become
 * staff is from the server. The account must already exist and have
 * verified its email: otherwise whoever registered first with someone
 * else's address would get the access.
 */
#[Signature('app:make-staff {email} {--revoke : Take staff access away instead}')]
#[Description('Give a registered user staff access, or take it away')]
class MakeStaff extends Command
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

        $staff = ! $this->option('revoke');

        if ($staff && $user->email_verified_at === null) {
            $this->error("{$email} hasn't verified its email address yet. Open the link in the verification email, then run this again.");

            return self::FAILURE;
        }

        if ($user->is_staff === $staff) {
            $this->info($staff ? "{$email} is already staff." : "{$email} isn't staff.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_staff' => $staff])->save();

        $this->info($staff ? "{$email} is now staff." : "{$email} is no longer staff.");

        return self::SUCCESS;
    }
}
