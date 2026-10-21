<?php

return [

    // How long the valley's homes are kept for the map, in seconds: busy valleys don't rebuild them for every look.
    'map_cache_seconds' => (int) env('MAP_CACHE_SECONDS', 3),

    // A valley takes newcomers until this many of its animals were active this week; then the next one opens.
    // About 100 factions of 15: more crowds the farms and leaves most factions without land.
    'valleys' => [
        'full_at' => (int) env('VALLEY_FULL_AT', 1500),
    ],

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
    // Set here rather than in the server's .env, so the site always shows them.
    'company_name' => 'Animal Farm',
    'information_officer' => 'The Animal Farm Dev Team',
    'contact_email' => env('CONTACT_EMAIL'),

    // The Terms and Conditions buyers agree to; a purchase records the version it was made under.
    'terms_version' => '2026-10-20',

    'season_days' => (int) env('GAME_SEASON_DAYS', 14),

    // Staying alive (App\Game\Survival): points of 100 lost each hour, what a Corn or a water fills, and the harm of going without.
    'survival' => [
        // Off only in tests that aren't about staying alive.
        'enabled' => (bool) env('GAME_SURVIVAL', true),
        'food_per_hour' => 2.0,
        'drink_per_hour' => 2.5,
        // An animal eats and drinks what it carries when it falls below this, up to eat_to.
        'eat_below' => 40,
        'eat_to' => 90,
        'food_per_corn' => 1.0,
        'drink_per_water' => 5.0,
        'starving_per_hour' => 6.0,
        'thirst_per_hour' => 8.0,
        // Fed and watered past this, it mends.
        'heal_above' => 30,
        'heal_per_hour' => 4.0,
        // A robbery's victim is roughed up.
        'robbed' => 10,
        // Where a newcomer, or an animal back from the dead, starts.
        'start' => ['food' => 60, 'drink' => 60],
    ],

    // A raid lands when its leader walks onto the land, not after a march (her rule, 2026-10-21).
    'raids' => [
        'on_arrival' => true,
    ],

    // Waits between crimes, and a robbed victim on guard: off (her rule, 2026-10-21).
    'crime' => [
        'cooldowns' => false,
    ],

    // The daily streak: day after day, each first visit pays a little more.
    'streak' => [
        'enabled' => true,
    ],

    // Only faction members may sell in the market (her rule, 2026-10-21).
    'market' => [
        'faction_only' => true,
        // Items sell for Apples only, never Corn (her rule, 2026-10-21).
        'apples_only' => true,
        // Recipes, the items with a power, belong to faction members only (her rule, 2026-10-21).
        'recipes_faction_only' => true,
    ],

    'horseshoes' => [
        // Every animal holds up to 500, filled again every day (her call, 2026-10-21; it was 100).
        'max' => 500,
        'refill_minutes' => 45,
        // Bought with Apples, for animals who don't want to wait: horseshoes => Apples. They stack past the usual 500.
        'packs' => [5 => 3, 10 => 5, 25 => 11],
        // The most an animal can hold, bought ones included.
        'saddlebag' => 1000,
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

    // Newcomers aren't protected from crime: anyone can be robbed or have their home burned from the start (her call, 2026-10-20).
    'newcomer_shield_days' => 0,

    'starting_corn' => 100,

    // The most an animal can send another in a day, of each: Corn, Apples, Pineapples, and of each material.
    'sends' => [
        'corn' => 1000,
        'apples' => 200,
        'pineapples' => 2000,
        'material' => 200,
    ],

    // What each region holds to gather when full, and how much grows back
    // an hour. Corn fields hold the most; the farms' rare crops the least.
    'stock' => [
        'corn' => ['max' => 400, 'per_hour' => 80],
        'material' => ['max' => 120, 'per_hour' => 24],
        'rare' => ['max' => 45, 'per_hour' => 9],
    ],

    // How far an animal walks in a minute, in paces (the map's units): a
    // region is about 270 paces across. A Speedo doubles it for an hour.
    'walk' => [
        'paces_per_minute' => 500,
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
