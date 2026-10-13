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
        // Municipal replies to disputes. The same model as bills unless set.
        'reply_model' => env('ANTHROPIC_REPLY_MODEL') ?: (env('ANTHROPIC_BILL_MODEL') ?: 'claude-opus-5-5'),
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
    | Prices, in rand.
    |
    | Pay as you go, for every account: a fixed price per job from prepaid
    | credit, and no monthly or setup fees. A bill check reads the bill and
    | runs the four checks; a dispute covers drafting it, emailing the
    | municipality, reading its replies, chasing and escalating.
    */
    'prices' => [
        'jobs' => [
            'bill_check' => env('PRICE_JOB_BILL_CHECK', '15'),
            'dispute' => env('PRICE_JOB_DISPUTE', '49'),
        ],
        'top_ups' => array_values(array_filter(array_map('trim', explode(',', (string) env('TOP_UP_PACKS', '50,100,250,500'))))),
        'welcome_credit' => env('WELCOME_CREDIT', '0'),
    ],

    /*
    | What Claude costs us, in US dollars per million tokens, to work out
    | the margin on each job. Writing to the prompt cache costs 1.25 times
    | the input price.
    */
    'claude_prices' => [
        'models' => [
            'claude-opus-5-5' => ['input' => '4.00', 'output' => '20.00', 'cache_read' => '0.20'],
            'claude-sonnet-5-5' => ['input' => '2.00', 'output' => '10.00', 'cache_read' => '0.20'],
            // No cache read price given for Haiku yet: cache reads are costed at
            // the input price until one is set, which overstates the cost.
            'claude-haiku-5-5' => ['input' => '0.10', 'output' => '0.50', 'cache_read' => null],
        ],
        'cache_write_multiplier' => '1.25',
        'usd_zar' => env('USD_ZAR_RATE', '18.50'),
    ],

    /*
    | The landing page's proof figures come from the founder's research notes
    | and still need checking. Figures without a source are hidden unless
    | this is on, which it is by default only while APP_DEBUG is on.
    */
    'show_unverified_claims' => (bool) env('SHOW_UNVERIFIED_CLAIMS', env('APP_DEBUG', false)),

];
