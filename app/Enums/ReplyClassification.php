<?php

namespace App\Enums;

/**
 * What a municipality's reply to a dispute says, as Claude read it.
 */
enum ReplyClassification: string
{
    /** Received, usually with a reference number. */
    case Acknowledgement = 'acknowledgement';
    /** They need something from the customer before they can decide. */
    case MoreInformation = 'more_information';
    /** The dispute is upheld or a credit is promised. */
    case Upheld = 'upheld';
    case Rejected = 'rejected';
    /** An out-of-office or other automatic answer. */
    case AutoReply = 'auto_reply';
    /** Nothing to do with this dispute. */
    case Unrelated = 'unrelated';
    /** Not read automatically: our team looks at it. */
    case Unread = 'unread';

    public function label(): string
    {
        return match ($this) {
            self::Acknowledgement => 'Acknowledged',
            self::MoreInformation => 'Asked for more information',
            self::Upheld => 'Upheld',
            self::Rejected => 'Rejected',
            self::AutoReply => 'Automatic reply',
            self::Unrelated => 'Unrelated',
            self::Unread => 'Waiting for our team',
        };
    }

    /** Replies that move the dispute on and that the customer hears about. */
    public function matters(): bool
    {
        return in_array($this, [self::Acknowledgement, self::MoreInformation, self::Upheld, self::Rejected], true);
    }
}
