<?php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;

/**
 * Customers only ever see their own organisation's disputes.
 */
class DisputePolicy
{
    public function view(User $user, Dispute $dispute): bool
    {
        return $user->organisation_id !== null && $user->organisation_id === $dispute->organisation_id;
    }

    public function update(User $user, Dispute $dispute): bool
    {
        return $this->view($user, $dispute);
    }

    public function delete(User $user, Dispute $dispute): bool
    {
        return $this->view($user, $dispute);
    }
}
