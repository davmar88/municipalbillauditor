<?php

namespace App\Http\Controllers;

use App\Enums\BillSource;
use App\Models\Organisation;
use App\Services\BillIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Forwarded e-bills. Customers forward their e-bill to <code>@<domain>; the
 * email provider's inbound webhook posts the message here as a multipart
 * form with the recipient in "to" (or "recipient") and the attachments as
 * files, and sends the shared secret in the X-Inbound-Secret header.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, BillIntake $intake): JsonResponse
    {
        $secret = (string) config('auditor.inbound_email_secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Inbound-Secret'))) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $organisation = $this->organisationFor((string) ($request->input('to') ?? $request->input('recipient')));
        $accepted = 0;

        if ($organisation !== null) {
            foreach (Arr::flatten($request->allFiles()) as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    continue;
                }

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

    private function organisationFor(string $recipients): ?Organisation
    {
        $domain = preg_quote(strtolower((string) config('auditor.inbound_email_domain')), '/');

        if (! preg_match_all('/([a-z0-9]+)@'.$domain.'/i', strtolower($recipients), $matches)) {
            return null;
        }

        return Organisation::query()->whereIn('forwarding_code', $matches[1])->first();
    }
}
