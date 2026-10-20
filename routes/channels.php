<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// An animal's own bell and private messages.
Broadcast::channel('player.{id}', fn (User $user, int $id) => $user->player?->id === $id);

// Everything happening in the animal's valley: the map and its chat.
Broadcast::channel('valley.{id}', fn (User $user, int $id) => $user->player?->valley_id === $id);
