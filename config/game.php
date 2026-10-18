<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The game's rules
    |--------------------------------------------------------------------------
    |
    | The numbers players feel: how fast horseshoes come back, how long a
    | season runs and which level unlocks what. The design document explains
    | each one. The test season runs at the lower levels below, so factions
    | and shops start in the first days instead of the first week. Founding
    | costs what a newcomer has after the story's first three steps, so the
    | first factions can start on day one.
    |
    */

    'timezone' => 'Africa/Johannesburg',

    // Shown in the privacy notice, as POPIA requires.
    'company_name' => env('COMPANY_NAME'),
    'information_officer' => env('INFORMATION_OFFICER'),
    'contact_email' => env('CONTACT_EMAIL'),

    'season_days' => (int) env('GAME_SEASON_DAYS', 14),

    'horseshoes' => [
        'max' => 10,
        'refill_minutes' => 45,
    ],

    'levels' => [
        'vote' => 1,
        'found_faction' => 1,
        'stall' => 2,
        'crime' => 3,
    ],

    'faction' => [
        'founding_corn' => 150,
        'max_members' => 15,
        'members_to_raid' => 3,
    ],

    'newcomer_shield_days' => 7,

    'starting_corn' => 100,

    // How far an animal walks in a minute, in paces (the map's units): a
    // region is about 270 paces across. A Speedo doubles it for an hour.
    'walk' => [
        'paces_per_minute' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pineapples and Apples
    |--------------------------------------------------------------------------
    |
    | Real money buys Pineapples, and Pineapples swap for packs of Apples, so
    | what an Apple costs in money takes two steps to work out. Apples change
    | hands between players; the game never hands them out free, so every
    | Apple was paid for by someone. Money is in US cents, paid through
    | PayPal, and bigger packs cost less. The owner puts the PayPal keys in and
    | opens sales on the admin page.
    |
    */

    'pineapples' => [
        // Each money pack buys the Apple pack of the same rank, with a few
        // Pineapples to spare on some.
        'packs' => [
            'pineapples-450' => ['pineapples' => 450, 'usd' => 300],
            'pineapples-900' => ['pineapples' => 900, 'usd' => 570],
            'pineapples-2200' => ['pineapples' => 2200, 'usd' => 1350],
            'pineapples-4200' => ['pineapples' => 4200, 'usd' => 2550],
            'pineapples-8200' => ['pineapples' => 8200, 'usd' => 4800],
        ],
    ],

    'apples' => [
        // Bought with Pineapples.
        'packs' => [
            'apples-50' => ['apples' => 50, 'pineapples' => 450],
            'apples-100' => ['apples' => 100, 'pineapples' => 880],
            'apples-250' => ['apples' => 250, 'pineapples' => 2150],
            'apples-500' => ['apples' => 500, 'pineapples' => 4200],
            'apples-1000' => ['apples' => 1000, 'pineapples' => 8200],
        ],
        // One way only: Corn never turns back into Apples, or the Corn
        // everyone earns free would become money.
        'corn_per_apple' => 10,
        // The share of a bounty the game keeps when a hunter collects it.
        'bounty_cut' => 20,
        // The share of a shop sale in Apples the game keeps. A maker's
        // royalty on a resale comes out of the seller's share.
        'shop_cut' => 15,
        'shop_min' => 5,
        'shop_max' => 1000,
    ],

    // Apple trees, planted with Pineapples. A tree fruits after a couple of
    // days and grows Apples until it has given them all; a full basket stops
    // it until the animal harvests. A tree costs 900 Pineapples ($5.27 to
    // $5.70) for 130 Apples, about 4.2c an Apple: less than a bought one
    // (4.8c to 6c), more than a cash-out pays (3.6c).
    'trees' => [
        'price' => 900,
        'apples' => 130,
        'per_day' => 5,
        'basket_days' => 3,
        'sapling_days' => 2,
        'max' => 5,
    ],

    // Orchard membership, bought with Apples: one free horseshoe refill a
    // day, a badge by the member's name and an extra shop shelf.
    'orchard' => [
        'price' => 60,
        'days' => 31,
    ],

    // Cashing Apples out to PayPal, once the owner opens it on the admin
    // page. The cheapest Apple costs 4.8c ($48 buys 8,200 Pineapples, which
    // swap for 1,000 Apples), and a cash-out pays 3.6c, so every cash-out
    // leaves the game ahead before PayPal's fees.
    'cashout' => [
        // What the game pays for 100 Apples, in US cents.
        'per_100' => 360,
        'min' => 500,
        'daily_cap' => 5000,
        // Apples an animal bought itself wait this long before they can be
        // cashed out, in case the card payment is reversed.
        'hold_days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat translation
    |--------------------------------------------------------------------------
    |
    | The Translate button sends one message to Claude and keeps the answer,
    | so a message is translated into each language once. Without a key the
    | button says translation isn't available.
    |
    */

    'translator' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('GAME_TRANSLATION_MODEL', 'claude-opus-5-5'),
        // A short translation needs little thinking.
        'effort' => env('GAME_TRANSLATION_EFFORT', 'low'),
        'timeout' => (float) env('ANTHROPIC_TIMEOUT', 30),
    ],

];
