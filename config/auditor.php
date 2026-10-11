<?php

return [

    /*
    | Bills and meter photos are personal financial data. They live on a
    | private disk and are only streamed to their owner, never linked.
    */
    'bill_disk' => env('BILL_DISK', 'local'),

    'max_upload_kb' => (int) env('BILL_MAX_UPLOAD_KB', 10240),

    /*
    | Reading bills with Claude. Without an API key, uploaded bills wait for
    | someone to type in the details.
    */
    'reader' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_BILL_MODEL') ?: 'claude-opus-5-5',
        'effort' => env('ANTHROPIC_BILL_EFFORT', 'medium'),
        'max_tokens' => 16000,
        'timeout' => (float) env('ANTHROPIC_TIMEOUT', 120),
    ],

    /*
    | Forwarded e-bills arrive at <code>@<domain> through an inbound email
    | webhook that sends this shared secret in the X-Inbound-Secret header.
    */
    'inbound_email_domain' => env('INBOUND_EMAIL_DOMAIN', 'bills.example.com'),
    'inbound_email_secret' => env('INBOUND_EMAIL_SECRET'),

    /*
    | Where people can write to us. The public pages only show contact links
    | once this is set.
    */
    'contact_email' => env('CONTACT_EMAIL'),

    /*
    | The responsible party named in the privacy notice (POPIA section 18).
    */
    'company_name' => env('COMPANY_NAME'),
    'information_officer' => env('INFORMATION_OFFICER'),

    /*
    | Prices. These are the pricing model's starting guesses, not decided
    | prices. Organisations can carry a negotiated rate instead.
    */
    'prices' => [
        'household_dispute' => env('PRICE_HOUSEHOLD_DISPUTE', '299'),
        'per_unit_month' => env('PRICE_PER_UNIT_MONTH', '50'),
        'success_fee_rate' => env('SUCCESS_FEE_RATE', '0.25'),
    ],

    /*
    | The landing page's proof figures come from the founder's research notes
    | and still need checking. Figures without a source are hidden unless
    | this is on, which it is by default only while APP_DEBUG is on.
    */
    'show_unverified_claims' => (bool) env('SHOW_UNVERIFIED_CLAIMS', env('APP_DEBUG', false)),

];
