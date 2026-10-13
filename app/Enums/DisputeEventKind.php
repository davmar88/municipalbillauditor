<?php

namespace App\Enums;

enum DisputeEventKind: string
{
    case Lodged = 'lodged';
    case Acknowledged = 'acknowledged';
    case ReplyReceived = 'reply_received';
    /** The municipality asked for more information. */
    case InfoRequested = 'info_requested';
    /** The municipality upheld the dispute or promised a credit. */
    case Upheld = 'upheld';
    case Rejected = 'rejected';
    /** We chased a missing acknowledgement on the same email thread. */
    case FollowedUp = 'followed_up';
    case EscalatedCouncillor = 'escalated_councillor';
    /** We escalated to the municipal manager. */
    case EscalatedManager = 'escalated_manager';
    case EscalatedPublicProtector = 'escalated_public_protector';
    case TariffFixed = 'tariff_fixed';
    /** Kept for disputes from the success-fee model. */
    case SavingSeen = 'saving_seen';
    case CreditReceived = 'credit_received';
    case Closed = 'closed';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Lodged => 'Dispute sent',
            self::Acknowledged => 'Acknowledged',
            self::ReplyReceived => 'Reply received',
            self::InfoRequested => 'More information asked for',
            self::Upheld => 'Upheld',
            self::Rejected => 'Rejected',
            self::FollowedUp => 'Followed up',
            self::EscalatedCouncillor => 'Escalated to ward councillor',
            self::EscalatedManager => 'Escalated to the municipal manager',
            self::EscalatedPublicProtector => 'Escalated to the Public Protector',
            self::TariffFixed => 'Tariff corrected',
            self::SavingSeen => 'Saving seen on a bill',
            self::CreditReceived => 'Credit received',
            self::Closed => 'Closed',
            self::Note => 'Note',
        };
    }
}
