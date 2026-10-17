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
    | and shops start in the first days instead of the first week.
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
        'found_faction' => 2,
        'stall' => 2,
        'crime' => 3,
    ],

    'faction' => [
        'founding_corn' => 200,
        'max_members' => 15,
        'members_to_raid' => 3,
    ],

    'newcomer_shield_days' => 7,

    'starting_corn' => 100,

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
