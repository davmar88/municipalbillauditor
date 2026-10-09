<?php

/*
|--------------------------------------------------------------------------
| Metros (municipalities)
|--------------------------------------------------------------------------
|
| Per-municipality dispute windows, response waits, dispute channels and
| escalation steps. Every value here is a sensible default that has NOT yet
| been checked against the municipality's credit control and debt
| collection by-law, so every metro is marked 'verified' => false. Clients
| show an "unverified" hint for these.
|
| Do not add email addresses, phone numbers or web addresses here unless
| they have been verified against the municipality's own published
| material. Until then users are told to use the channel printed on their
| own statement.
|
*/

$defaultDisputeChannels = [
    [
        'type' => 'statement',
        'label' => 'Billing query channel',
        'value' => 'Use the query email, portal or walk-in centre printed on your municipal statement.',
    ],
];

$defaultEscalationSteps = [
    [
        'level' => 0,
        'name' => 'Billing query',
        'description' => "Written dispute to the municipality's billing or revenue department.",
    ],
    [
        'level' => 1,
        'name' => 'Senior revenue official',
        'description' => 'If you get no answer in time, or the answer does not fix the problem, write to the head of the revenue department or the municipal manager. Include your query reference number and a copy of your first letter.',
    ],
    [
        'level' => 2,
        'name' => 'Ward councillor or municipal ombudsman',
        'description' => "Ask your ward councillor to take the matter up for you, or lodge a complaint with the municipality's ombudsman if it has one. Share your reference numbers and copies of your letters.",
    ],
    [
        'level' => 3,
        'name' => 'Public Protector',
        'description' => 'If the municipality still has not dealt with your dispute, you can lodge a complaint with the Public Protector, who looks into poor service and maladministration by government bodies, including municipalities.',
    ],
];

$metro = fn (string $name): array => [
    'name' => $name,
    'dispute_window_days' => 30,
    'response_wait_days' => 30,
    'dispute_channels' => $defaultDisputeChannels,
    'escalation_steps' => $defaultEscalationSteps,
    'verified' => false,
];

return [
    'johannesburg' => $metro('City of Johannesburg Metropolitan Municipality'),
    'tshwane' => $metro('City of Tshwane Metropolitan Municipality'),
    'cape_town' => $metro('City of Cape Town Metropolitan Municipality'),
    'ethekwini' => $metro('eThekwini Metropolitan Municipality'),
    'ekurhuleni' => $metro('City of Ekurhuleni Metropolitan Municipality'),
    'nelson_mandela_bay' => $metro('Nelson Mandela Bay Metropolitan Municipality'),
    'buffalo_city' => $metro('Buffalo City Metropolitan Municipality'),
    'mangaung' => $metro('Mangaung Metropolitan Municipality'),
    'other' => $metro('Other municipality'),
];
