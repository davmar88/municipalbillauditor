<?php

namespace App\Services\Inbound;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reads an inbound email webhook. Everything that depends on the email
 * provider is here.
 *
 * Mailgun (the default) forwards each message as a multipart post with
 * recipient, sender, subject, body-plain, stripped-text, Message-Id,
 * In-Reply-To and attachment-N files, signed with timestamp, token and
 * signature: an HMAC-SHA256 of the timestamp followed by the token, keyed
 * with the webhook signing key. Stale or reused signatures are refused.
 *
 * Other providers can post the same fields with our shared secret in the
 * X-Inbound-Secret header instead.
 */
class InboundEmailParser
{
    /** How old a Mailgun signature may be, in seconds. */
    public const MAX_AGE = 300;

    /**
     * The message, or null when the post can't be shown to come from our
     * provider.
     */
    public function parse(Request $request): ?InboundMessage
    {
        if (! $this->verified($request)) {
            return null;
        }

        $field = fn (string ...$names) => collect($names)
            ->map(fn (string $name) => $request->input($name))
            ->first(fn ($value) => is_string($value) && trim($value) !== '');

        return new InboundMessage(
            recipients: (string) $field('recipient', 'to', 'To'),
            from: Str::limit((string) $field('sender', 'from', 'From'), 250, ''),
            subject: Str::limit((string) $field('subject', 'Subject'), 250, ''),
            text: (string) ($field('stripped-text', 'body-plain', 'text', 'body') ?? strip_tags((string) $field('body-html', 'stripped-html', 'html'))),
            messageId: self::id($field('Message-Id', 'message-id', 'message_id')),
            inReplyTo: self::id($field('In-Reply-To', 'in-reply-to', 'in_reply_to')),
            attachments: array_values(array_filter(
                Arr::flatten($request->allFiles()),
                fn ($file) => $file instanceof UploadedFile && $file->isValid(),
            )),
        );
    }

    private function verified(Request $request): bool
    {
        $secret = (string) config('auditor.inbound_email_secret');

        if ($request->hasHeader('X-Inbound-Secret')) {
            return $secret !== '' && hash_equals($secret, (string) $request->header('X-Inbound-Secret'));
        }

        return $this->mailgunSigned($request);
    }

    private function mailgunSigned(Request $request): bool
    {
        $key = (string) config('services.mailgun.webhook_signing_key');
        $timestamp = (string) $request->input('timestamp');
        $token = (string) $request->input('token');
        $signature = (string) $request->input('signature');

        if ($key === '' || $timestamp === '' || $token === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_AGE) {
            return false;
        }

        if (! hash_equals(hash_hmac('sha256', $timestamp.$token, $key), $signature)) {
            return false;
        }

        // A signature is good for one post: a replayed one is refused.
        return Cache::add('mailgun-token:'.$token, true, self::MAX_AGE * 2);
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 250, '') : null;
    }
}
