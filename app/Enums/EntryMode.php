<?php

namespace App\Enums;

/** How a faction takes new members. */
enum EntryMode: string
{
    case Open = 'open';
    case Approval = 'approval';
    case InviteOnly = 'invite';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open to anyone who meets the rules',
            self::Approval => 'The leader or council approves each request',
            self::InviteOnly => 'Invite only',
        };
    }
}
