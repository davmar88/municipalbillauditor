<?php

namespace App\Policies;

use App\Models\Bill;
use App\Models\User;

/**
 * Customers only ever see their own organisation's bills.
 */
class BillPolicy
{
    public function view(User $user, Bill $bill): bool
    {
        return $user->organisation_id !== null && $user->organisation_id === $bill->organisation_id;
    }

    public function update(User $user, Bill $bill): bool
    {
        return $this->view($user, $bill);
    }

    public function delete(User $user, Bill $bill): bool
    {
        return $this->view($user, $bill);
    }
}
