<?php

namespace App\Http\Controllers;

use App\Enums\BillSource;
use App\Jobs\ReadDisputeReply;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Organisation;
use App\Services\BillIntake;
use App\Services\Inbound\InboundEmailParser;
use App\Services\Inbound\InboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Email arriving at our inbound domain, from the provider's webhook.
 *
 * Municipalities reply to disputes at d-<token>@<domain>: those replies
 * are kept and read. Customers forward e-bills to <code>@<domain>: those
 * bills are checked. InboundEmailParser verifies and reads the post,
 * whichever provider sent it.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, InboundEmailParser $parser, BillIntake $intake): JsonResponse
    {
        $message = $parser->parse($request);

        if ($message === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (($dispute = $this->disputeFor($message->recipients)) !== null) {
            $this->storeReply($message, $dispute);

            return response()->json(['accepted' => 1], 202);
        }

        $organisation = $this->organisationFor($message->recipients);
        $accepted = 0;

        if ($organisation !== null) {
            foreach ($message->attachments as $file) {
                $mime = (string) $file->getMimeType();
                $tooBig = $file->getSize() > (int) config('auditor.max_upload_kb') * 1024;

                if (! in_array($mime, BillIntake::ACCEPTED_MIME_TYPES, true) || $tooBig) {
                    continue;
                }

                $intake->store($organisation, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), $mime, null, null, BillSource::Email);
                $accepted++;
            }
        }

        // The same answer whether or not the address exists, so the webhook
        // can't be used to discover customers.
        return response()->json(['accepted' => $accepted], 202);
    }

    private function disputeFor(string $recipients): ?Dispute
    {
        $domain = preg_quote(strtolower((string) config('auditor.inbound_email_domain')), '/');

        if (! preg_match_all('/d-([a-z0-9]{24})@'.$domain.'/i', strtolower($recipients), $matches)) {
            return null;
        }

        return Dispute::query()->whereIn('reply_token', $matches[1])->first();
    }

    /**
     * Keep the reply, with its PDF and image attachments, and have it read.
     * A provider posting the same message again is ignored.
     */
    private function storeReply(InboundMessage $message, Dispute $dispute): void
    {
        $duplicate = $message->messageId !== null && $dispute->messages()
            ->where('direction', DisputeMessage::IN)
            ->where('message_id', $message->messageId)
            ->exists();

        if ($duplicate) {
            return;
        }

        $files = [];
        $disk = Storage::disk(config('auditor.bill_disk'));

        foreach ($message->attachments as $file) {
            $mime = (string) $file->getMimeType();

            if (! in_array($mime, BillIntake::ACCEPTED_MIME_TYPES, true) || $file->getSize() > (int) config('auditor.max_upload_kb') * 1024) {
                continue;
            }

            // A random name: the stored path never carries the sender's file name.
            $path = "dispute-replies/{$dispute->id}/".Str::uuid().'.'.($file->guessExtension() ?: 'bin');
            $disk->put($path, (string) file_get_contents($file->getRealPath()));
            $files[] = ['path' => $path, 'name' => Str::limit(basename($file->getClientOriginalName()), 200, ''), 'mime' => $mime, 'size' => (int) $file->getSize()];
        }

        $reply = $dispute->messages()->create([
            'direction' => DisputeMessage::IN,
            'kind' => 'reply',
            'from_address' => $message->from,
            'to_address' => $message->recipients,
            'subject' => $message->subject,
            'body' => $message->text,
            'attachments' => $files === [] ? null : $files,
            'message_id' => $message->messageId,
            'in_reply_to' => $message->inReplyTo,
        ]);

        ReadDisputeReply::dispatch($reply)->afterCommit();
    }

    private function organisationFor(string $recipients): ?Organisation
    {
        $domain = preg_quote(strtolower((string) config('auditor.inbound_email_domain')), '/');

        if (! preg_match_all('/(?<![a-z0-9-])([a-z0-9]+)@'.$domain.'/i', strtolower($recipients), $matches)) {
            return null;
        }

        return Organisation::query()->whereIn('forwarding_code', $matches[1])->first();
    }
}
