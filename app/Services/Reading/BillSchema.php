<?php

namespace App\Services\Reading;

/**
 * What we ask Claude for when it reads a bill, as a JSON schema for
 * structured outputs. Structured outputs guarantee the shape, not the
 * values: ExtractedBill still checks the numbers.
 */
final class BillSchema
{
    public const SYSTEM = <<<'TEXT'
        You read South African municipal statements of account for a service that checks them for billing errors.
        Transcribe what the bill says. Never estimate or infer a value that isn't printed: use null instead, and
        say what you couldn't read in unreadable_notes. A wrong number creates a false alarm for the customer.
        TEXT;

    public const INSTRUCTIONS = <<<'TEXT'
        Read this municipal bill.

        - Dates as YYYY-MM-DD. The billing period is the period the consumption covers; if the bill only shows
          reading dates, use those.
        - Amounts in rand as plain numbers with a dot for decimals and no separators or currency symbol:
          R1 596,00 or R1,596.00 becomes 1596.00.
        - Meter readings: one entry per meter. reading_type is "estimated" when the bill marks the reading as
          estimated, for example with E, Est, Estimated, Interim or Deemed; "actual" when it is marked as an
          actual reading; otherwise "unknown".
        - Charges: this period's charges only, one entry per line. Leave out the opening balance, payments
          received and interest on arrears. Credits and adjustments are negative amounts.
        - tariff is the tariff or account category exactly as printed, such as Residential, Business or
          Bulk Residential.
        - municipality is the issuing municipality's name as printed, such as City of Tshwane or Stellenbosch
          Municipality. The municipality_* fields are the contact details the bill prints for account or billing
          queries: copy them exactly, and use null for any that aren't printed. Never guess an email address.
        TEXT;

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $string = self::nullable(['type' => 'string']);
        $number = self::nullable(['type' => 'number']);
        $date = self::nullable(['type' => 'string', 'format' => 'date']);

        return self::object([
            'is_municipal_bill' => ['type' => 'boolean', 'description' => 'False if this is not a municipal statement of account.'],
            'municipality' => $string + ['description' => 'The municipality that issued the bill, as printed.'],
            'municipality_email' => $string + ['description' => 'The email address printed for account or billing queries.'],
            'municipality_postal_address' => $string + ['description' => "The municipality's postal address as printed."],
            'municipality_phone' => $string + ['description' => 'The phone number printed for account queries.'],
            'account_number' => $string + ['description' => 'The municipal account number exactly as printed.'],
            'bill_date' => $date + ['description' => 'Statement or invoice date.'],
            'due_date' => $date,
            'period_start' => $date,
            'period_end' => $date,
            'property_address' => $string + ['description' => 'The address of the property the account is for.'],
            'suburb' => $string + ['description' => "The property's suburb."],
            'tariff' => $string + ['description' => 'Tariff or account category as printed. Null if none is printed.'],
            'total_due' => $number + ['description' => 'Total amount due on this bill, in rand.'],
            'meter_readings' => [
                'type' => 'array',
                'items' => self::object([
                    'service' => ['type' => 'string', 'enum' => ['water', 'electricity', 'other']],
                    'meter_number' => $string,
                    'previous' => $number,
                    'current' => $number,
                    'consumption' => $number + ['description' => 'Units used this period, as printed.'],
                    'unit' => $string + ['description' => 'kL for water, kWh for electricity.'],
                    'reading_type' => ['type' => 'string', 'enum' => ['actual', 'estimated', 'unknown']],
                ]),
            ],
            'charges' => [
                'type' => 'array',
                'items' => self::object([
                    'service' => ['type' => 'string', 'enum' => ['water', 'electricity', 'sanitation', 'refuse', 'rates', 'other']],
                    'description' => ['type' => 'string'],
                    'amount' => ['type' => 'number'],
                    'quantity' => $number + ['description' => 'Units this line charges for, when printed.'],
                    'unit' => $string,
                ]),
            ],
            'unreadable_notes' => $string + ['description' => "Anything on the bill you couldn't read clearly."],
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        // Structured outputs need additionalProperties false on every object;
        // every field is required and nullable where it may be missing.
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private static function nullable(array $type): array
    {
        return ['anyOf' => [$type, ['type' => 'null']]];
    }
}
