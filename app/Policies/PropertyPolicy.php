<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

/**
 * Customers only ever see their own organisation's propertys.
 */
class PropertyPolicy
{
    public function view(User $user, Property $property): bool
    {
        return $user->organisation_id !== null && $user->organisation_id === $property->organisation_id;
    }

    public function update(User $user, Property $property): bool
    {
        return $this->view($user, $property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $this->view($user, $property);
    }
}
