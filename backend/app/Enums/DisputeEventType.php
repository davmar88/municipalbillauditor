<?php

namespace App\Enums;

enum DisputeEventType: string
{
    use EnumValues;

    case Created = 'created';
    case LetterEdited = 'letter_edited';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';
    case ResponseReceived = 'response_received';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Note = 'note';
}
