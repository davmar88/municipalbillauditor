<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** What's new and what's fixed, for the public updates page; the owner adds to it on /admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_updates', function (Blueprint $table) {
            $table->id();
            $table->date('published_on');
            $table->string('kind', 8);
            $table->string('title', 120);
            $table->text('body');
            $table->timestamps();
            $table->index('published_on');
        });

        $now = now();
        DB::table('game_updates')->insert(array_map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now], [
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Rob other animals', 'body' => "Tap an animal standing near you on the map and rob it: you take a share of the Corn and materials it carries. Robbers lie low for two hours, and a victim is left alone for six. Get caught and it's the pound."],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Buy horseshoes with Apples', 'body' => "Don't want to wait? Tap your horseshoes at the top of any page to buy 5, 10 or 25 with Apples. Bought horseshoes stack past ten, up to 40."],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => "Who's online", 'body' => 'A new page lists every animal about in your valley right now, with its faction and where it stands.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Report a bug', 'body' => 'Something broken? Report it from the menu, with a screenshot or a short video. You can see what happened to your report on the same page.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Set any home alight from the map', 'body' => "Tap any animal's home on the map to see whose it is and set it alight, your own faction's included."],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Terms and Conditions', 'body' => 'Buying Pineapples now asks you to accept the terms and conditions first.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'New valleys', 'body' => 'When a valley fills up, newcomers start in the next one, with its own map and season.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Live updates', 'body' => 'The map now updates itself the moment anything happens, and messages, chat and notices pop up as they arrive.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Pay with PayPal without leaving the game', 'body' => 'PayPal now opens in its own window over the Apples page, or as a card form right in the pack.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Skin shop for homes', 'body' => 'Dress your home in one of eight skins: the first four cost Corn, the finest cost Apples. Each makes your home harder to burn and harder to arrest anyone hiding inside.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => "Irongate's plunderers", 'body' => "Bands of plunderers ride out of Irongate for a faction's farm every few hours in daylight. Every faction can fight them together: win and share their Corn; lose and they sack the farm."],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Homes burn to ash', 'body' => "A home being set alight burns with real flames for half an hour. Anyone can run to the door and put it out. If it burns down, ash and charred beams stand where it was until it's rebuilt."],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Hospital and town houses', 'body' => 'Factions can build a Hospital: members beaten in a fight are laid up less long. Eight new town looks for homes, from a family house to a corner shop.'],
            ['published_on' => '2026-10-20', 'kind' => 'new', 'title' => 'Bounties from any faction', 'body' => "Any faction's leader and council can now put a bounty on an outlaw, and a caught outlaw pays back what their crimes took."],
            ['published_on' => '2026-10-20', 'kind' => 'fix', 'title' => "Burning a home that wasn't there", 'body' => 'You could set fire to an animal who had no home. Now only a home that stands can burn.'],
            ['published_on' => '2026-10-20', 'kind' => 'fix', 'title' => '"A lone wolf" in faction crime notices', 'body' => 'Notices called faction members who were caught "a lone wolf". They now name who it was.'],
            ['published_on' => '2026-10-20', 'kind' => 'fix', 'title' => 'Offline animals vanishing', 'body' => "Animals who hadn't played for a week dropped off the map. Everyone stays on it now, and can still be robbed."],
            ['published_on' => '2026-10-20', 'kind' => 'fix', 'title' => 'A faster map in busy valleys', 'body' => 'The map loads three to four times faster when the valley is full of animals.'],
            ['published_on' => '2026-10-19', 'kind' => 'new', 'title' => 'Faction members can loot, plunder and burn', 'body' => 'Faction members can turn on any faction, their own included. Factions can set a rule that gives members a strike for it.'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('game_updates');
    }
};
