<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A chat message translated into one language, kept for everyone who asks.
 *
 * @property int $id
 * @property int $chat_message_id
 * @property string $language
 * @property string $body
 */
#[Fillable(['chat_message_id', 'language', 'body'])]
class ChatTranslation extends Model
{
    public const UPDATED_AT = null;
}
