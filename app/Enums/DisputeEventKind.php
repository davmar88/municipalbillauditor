<?php

namespace App\Enums;

enum DisputeEventKind: string
{
    case Lodged = 'lodged';
    case Acknowledged = 'acknowledged';
    case ReplyReceived = 'reply_received';
    case EscalatedCouncillor = 'escalated_councillor';
    case EscalatedPublicProtector = 'escalated_public_protector';
    case TariffFixed = 'tariff_fixed';
    case SavingSeen = 'saving_seen';
    case CreditReceived = 'credit_received';
    case Closed = 'closed';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Lodged => 'Dispute lodged',
            self::Acknowledged => 'Acknowledged',
            self::ReplyReceived => 'Reply received',
            self::EscalatedCouncillor => 'Escalated to ward councillor',
            self::EscalatedPublicProtector => 'Escalated to the Public Protector',
            self::TariffFixed => 'Tariff corrected',
            self::SavingSeen => 'Saving seen on a bill',
            self::CreditReceived => 'Credit received',
            self::Closed => 'Closed',
            self::Note => 'Note',
        };
    }
}
