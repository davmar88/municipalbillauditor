<?php

namespace App\Policies;

use App\Models\Finding;
use App\Models\User;

class FindingPolicy
{
    public function update(User $user, Finding $finding): bool
    {
        return $user->organisation_id !== null && $user->organisation_id === $finding->bill->organisation_id;
    }
}
