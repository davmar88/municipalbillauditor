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

    /*
    |--------------------------------------------------------------------------
    | Apples
    |--------------------------------------------------------------------------
    |
    | Apples are bought with real money and change hands between players. The
    | game never hands them out free, so every Apple was paid for by someone.
    | Prices are in cents, and bigger packs cost less per Apple. The owner
    | puts the payment keys in and opens sales on the admin page.
    |
    */

    'apples' => [
        'packs' => [
            'apples-50' => ['apples' => 50, 'zar' => 5000, 'usd' => 300],
            'apples-100' => ['apples' => 100, 'zar' => 9500, 'usd' => 570],
            'apples-250' => ['apples' => 250, 'zar' => 22500, 'usd' => 1350],
            'apples-500' => ['apples' => 500, 'zar' => 42500, 'usd' => 2550],
            'apples-1000' => ['apples' => 1000, 'zar' => 80000, 'usd' => 4800],
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

    // Orchard membership, bought with Apples: one free horseshoe refill a
    // day, a badge by the member's name and an extra shop shelf.
    'orchard' => [
        'price' => 60,
        'days' => 31,
    ],

    // Cashing Apples out for real money, once the owner opens it on the
    // admin page. The cheapest Apple costs 80c (R800 for 1,000), and a
    // cash-out pays 60c, so every cash-out leaves the game ahead.
    'cashout' => [
        // What the game pays for 100 Apples, in cents. Bitcoin is worked
        // out in rand.
        'per_100' => ['ZAR' => 6000, 'USD' => 360],
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
