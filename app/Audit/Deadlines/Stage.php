<?php

namespace App\Audit\Deadlines;

enum Stage: string
{
    case Draft = 'draft';
    case AwaitingReply = 'awaiting_reply';
    case ReplyOverdue = 'reply_overdue';
    case AwaitingCredit = 'awaiting_credit';
    case WithCouncillor = 'with_councillor';
    case CouncillorOverdue = 'councillor_overdue';
    case WithPublicProtector = 'with_public_protector';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Not lodged yet',
            self::AwaitingReply => 'Waiting for a reply',
            self::ReplyOverdue => 'Reply overdue',
            self::AwaitingCredit => 'Waiting for the credit',
            self::WithCouncillor => 'With the councillor',
            self::CouncillorOverdue => 'Ready for the Public Protector',
            self::WithPublicProtector => 'With the Public Protector',
            self::Resolved => 'Credit received',
            self::Closed => 'Closed',
        };
    }

    /** Stages that need someone to act now. */
    public function needsAction(): bool
    {
        return in_array($this, [self::Draft, self::ReplyOverdue, self::CouncillorOverdue], true);
    }

    public function isOpen(): bool
    {
        return $this !== self::Resolved && $this !== self::Closed;
    }
}
