<?php

/*
|--------------------------------------------------------------------------
| Fair play
|--------------------------------------------------------------------------
|
| The rules against cheating, each with a code. The public "No cheating"
| page lists them, and a player reporting someone for cheating picks the
| rule they broke.
|
*/

return [
    'rules' => [
        'FP1' => [
            'title' => 'One animal per person',
            'text' => 'Play one account, with one animal, in your own name. Second accounts ("alts"), playing someone else\'s account, and sharing, lending or selling accounts are all against the rules.',
            'examples' => ['Making a second account to fight on both sides, vote twice or fill a faction.', 'Letting a friend play your animal while you\'re away.'],
        ],
        'FP2' => [
            'title' => 'No feeding between accounts',
            'text' => 'Don\'t move Corn, Apples, materials or items between accounts that belong to the same person, or arrange with a friend to pass things on for someone else.',
            'examples' => ['Selling an item to your own second account for a high price.', 'Robbing or gifting a second account to build up your main one.'],
        ],
        'FP3' => [
            'title' => 'No bots, scripts or automation',
            'text' => 'Play by hand. Bots, scripts, macros, auto-clickers, browser extensions that play for you, and modified game files are not allowed.',
            'examples' => ['A script that gathers or walks every few minutes.', 'A tool that taps "Fight" the moment a band of plunderers appears.'],
        ],
        'FP4' => [
            'title' => 'No bug abuse',
            'text' => 'If something in the game gives you more than it should, report it and stop. Using a bug on purpose, or telling others how to, is cheating.',
            'examples' => ['Repeating an action that pays twice.', 'Getting into a place or a state the game should not allow.'],
        ],
        'FP5' => [
            'title' => 'No rigged fights or fake crimes',
            'text' => 'Don\'t arrange fake raids, crimes, arrests or bounties with other players to farm Rosettes, rewards, bounties or land.',
            'examples' => ['Two factions trading a farm back and forth for raid rewards.', 'A "crime" set up so a friend can collect the bounty.'],
        ],
        'FP6' => [
            'title' => 'No real-money trading',
            'text' => 'Game content stays in the game. Don\'t buy or sell Pineapples, Apples, items, accounts or services for real money or anything outside the game, except through the game\'s own shop and cash-outs.',
            'examples' => ['Selling Apples for cash on social media.', 'Paying someone to level up your animal.'],
        ],
        'FP7' => [
            'title' => 'No payment fraud',
            'text' => 'Only pay with your own PayPal account or card, and don\'t charge a payment back after the Pineapples arrived.',
            'examples' => ['Buying with a card that isn\'t yours.', 'Disputing a purchase you made and used.'],
        ],
        'FP8' => [
            'title' => 'No scams, threats or harassment',
            'text' => 'Trick, threaten, bully or impersonate nobody, and don\'t try to get anyone\'s password, money or personal details. Rivalry in the game is fine; abuse is not.',
            'examples' => ['Promising a trade, taking the payment and not delivering outside the game\'s own Market.', 'Pretending to be one of our team.'],
        ],
        'FP9' => [
            'title' => 'No attacking the game',
            'text' => 'No hacking, probing, overloading or tampering with the site, its servers or other players\' accounts.',
            'examples' => ['Sending made-up requests to the game\'s servers.', 'Trying to get into another player\'s account.'],
        ],
    ],

    // What can happen to a player who cheats, from a warning to closing the account.
    'penalties' => [
        'A warning, or being silenced in chat.',
        'Losing what cheating earned: Corn, Apples, items, Rosettes, land or rewards.',
        'Trades, bounties and cash-outs reversed or cancelled.',
        'A suspension for a while.',
        'The account closed for good, for serious or repeated cheating, without a refund.',
    ],
];
