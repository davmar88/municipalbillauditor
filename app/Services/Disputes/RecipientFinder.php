<?php

namespace App\Services\Disputes;

use App\Models\Bill;
use App\Models\Dispute;

/**
 * Where a dispute goes. Customers never have to know: the first address we
 * have, in this order, is used.
 *
 * 1. The municipality's billing email, verified by our staff.
 * 2. The account query email printed on the customer's own bill.
 * 3. The municipality's billing email from the directory, unverified.
 *
 * With none of these, the dispute is held. We never guess an address.
 */
class RecipientFinder
{
    public const VERIFIED = 'verified_directory';

    public const BILL = 'bill';

    public const DIRECTORY = 'directory';

    /**
     * @return array{email: string, source: string}|null
     */
    public function for(Dispute $dispute): ?array
    {
        $municipality = $dispute->property?->municipality;

        if ($municipality?->billing_email !== null && $municipality->billing_email_verified_at !== null) {
            return ['email' => $municipality->billing_email, 'source' => self::VERIFIED];
        }

        $printed = $dispute->bill?->municipality_email ?? Bill::query()
            ->where('property_id', $dispute->property_id)
            ->whereNotNull('municipality_email')
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->value('municipality_email');

        if ($printed !== null) {
            return ['email' => $printed, 'source' => self::BILL];
        }

        if ($municipality?->billing_email !== null) {
            return ['email' => $municipality->billing_email, 'source' => self::DIRECTORY];
        }

        return null;
    }
}
