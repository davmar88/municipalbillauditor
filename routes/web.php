<?php

use App\Http\Controllers\Admin\CashoutController as CashoutQueueController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Game\AppleController;
use App\Http\Controllers\Game\BaseController;
use App\Http\Controllers\Game\BattleController;
use App\Http\Controllers\Game\CashoutController;
use App\Http\Controllers\Game\ChatController;
use App\Http\Controllers\Game\CrimeController;
use App\Http\Controllers\Game\FactionController;
use App\Http\Controllers\Game\MapController;
use App\Http\Controllers\Game\MarketController;
use App\Http\Controllers\Game\MessageController;
use App\Http\Controllers\Game\NoticeController;
use App\Http\Controllers\Game\OrchardController;
use App\Http\Controllers\Game\ProfileController;
use App\Http\Controllers\Game\RankingsController;
use App\Http\Controllers\Game\StartController;
use App\Http\Controllers\Game\TodayController;
use App\Http\Controllers\Game\TreeController;
use App\Http\Controllers\Game\WallController;
use App\Http\Controllers\Game\WantedController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PuzzleController;
use App\Http\Controllers\Staff\DesignController;
use App\Http\Controllers\Staff\ReportController;
use Illuminate\Support\Facades\Route;

// Public pages, server-rendered for search engines.
Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('sitemap.xml', [PageController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [PageController::class, 'robots'])->name('robots');

// Spot the Edit: anyone can play; players earn Rosettes for solving it.
Route::get('spot-the-edit', [PuzzleController::class, 'show'])->name('puzzle');
Route::post('spot-the-edit', [PuzzleController::class, 'solve'])->middleware(['auth', 'verified', 'player', 'throttle:10,1,puzzle'])->name('puzzle.solve');

// Paystack reports each payment here, signed with our secret key.
Route::post('webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:120,1,webhooks')->name('webhooks.paystack');

// "Continue with Google", for signing in and signing up.
Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('play/start', [StartController::class, 'create'])->name('play.start');
    Route::post('play/start', [StartController::class, 'store'])->middleware('throttle:10,1,start')->name('play.start.store');
});

// A throttle's last part names its own count. Without one, every throttle
// shares the player's count, so a limit of 20 on chat would also count the
// pages they opened.
Route::middleware(['auth', 'verified', 'player', 'throttle:120,1'])->prefix('play')->group(function () {
    Route::get('/', [MapController::class, 'index'])->name('play');
    Route::post('regions/{region}/walk', [MapController::class, 'walk'])->name('play.walk');
    Route::post('regions/{region}/gather', [MapController::class, 'gather'])->name('play.gather');
    Route::post('regions/{region}/buy', [MapController::class, 'buy'])->name('play.buy-farm');
    Route::post('regions/{region}/grow', [MapController::class, 'grow'])->name('play.grow-farm');
    Route::post('regions/{region}/attack', [BattleController::class, 'launch'])->name('play.attack');
    Route::post('attacks/{attack}/commit', [BattleController::class, 'commit'])->name('play.commit');
    Route::post('gate', [BattleController::class, 'gate'])->name('play.gate');

    Route::get('wall', [WallController::class, 'index'])->name('play.wall');
    Route::post('wall/laws', [WallController::class, 'setLaw'])->name('play.wall.law');
    Route::post('wall/secret', [WallController::class, 'secretEdit'])->name('play.wall.secret');
    Route::post('wall/remember', [WallController::class, 'remember'])->name('play.wall.remember');
    Route::post('wall/expose', [WallController::class, 'expose'])->name('play.wall.expose');
    Route::post('wall/festival', [WallController::class, 'festival'])->name('play.wall.festival');
    Route::post('wall/chant', [WallController::class, 'chant'])->name('play.wall.chant');
    Route::post('uprisings', [WallController::class, 'callUprising'])->name('play.uprising.call');
    Route::post('uprisings/{uprising}/pledge', [WallController::class, 'pledge'])->name('play.uprising.pledge');

    Route::get('faction', [FactionController::class, 'index'])->name('play.faction');
    Route::post('factions', [FactionController::class, 'store'])->name('play.faction.store');
    Route::post('factions/{faction}/join', [FactionController::class, 'join'])->name('play.faction.join');
    Route::post('faction/requests/{joinRequest}/answer', [FactionController::class, 'answer'])->name('play.faction.answer');
    Route::post('faction/leave', [FactionController::class, 'leave'])->name('play.faction.leave');
    Route::put('faction/rules', [FactionController::class, 'rules'])->name('play.faction.rules');
    Route::put('faction/members/{member}/role', [FactionController::class, 'role'])->name('play.faction.role');
    Route::delete('faction/members/{member}', [FactionController::class, 'remove'])->name('play.faction.remove');

    Route::post('faction/give', [BaseController::class, 'give'])->name('play.give');
    Route::post('regions/{region}/build', [BaseController::class, 'build'])->name('play.build');
    Route::post('buildings/{building}/upgrade', [BaseController::class, 'upgrade'])->name('play.upgrade');
    Route::post('faction/study', [BaseController::class, 'study'])->name('play.study');

    Route::post('crimes', [CrimeController::class, 'store'])->name('play.crime');

    // The House's warrants and the bounty hunters who serve them.
    Route::get('wanted', [WantedController::class, 'index'])->name('play.wanted');
    Route::post('warrants', [WantedController::class, 'issue'])->name('play.warrant.issue');
    Route::post('warrants/{warrant}/raise', [WantedController::class, 'raise'])->name('play.warrant.raise');
    Route::post('warrants/{warrant}/arrest', [WantedController::class, 'arrest'])->middleware('throttle:20,1,arrests')->name('play.warrant.arrest');
    Route::post('home/rebuild', [CrimeController::class, 'rebuild'])->name('play.rebuild');

    Route::get('chat', [ChatController::class, 'index'])->name('play.chat');
    Route::post('chat', [ChatController::class, 'store'])->middleware('throttle:20,1,chat')->name('play.chat.store');
    Route::post('chat/{message}/translate', [ChatController::class, 'translate'])->middleware('throttle:30,1,chat-translate')->name('play.chat.translate');

    Route::get('market', [MarketController::class, 'index'])->name('play.market');
    Route::post('designs', [MarketController::class, 'design'])->name('play.design');
    Route::post('designs/{design}/craft', [MarketController::class, 'craft'])->name('play.craft');
    Route::post('items/{item}/list', [MarketController::class, 'list'])->name('play.list');
    Route::post('items/{item}/use', [MarketController::class, 'use'])->name('play.use');
    Route::delete('listings/{listing}', [MarketController::class, 'unlist'])->name('play.unlist');
    Route::post('listings/{listing}/buy', [MarketController::class, 'buy'])->name('play.buy');

    // Growing a player: missions, skills and rankings.
    Route::get('today', [TodayController::class, 'index'])->name('play.today');
    Route::post('missions/{mission}/claim', [TodayController::class, 'claim'])->name('play.mission.claim');
    Route::get('rankings', [RankingsController::class, 'index'])->name('play.rankings');

    // Apples: buying packs, coming back from paying, swapping for Corn and cashing out.
    Route::get('apples', [AppleController::class, 'index'])->name('play.apples');
    Route::post('apples/buy', [AppleController::class, 'buy'])->middleware('throttle:10,1,apple-buy')->name('play.apples.buy');
    Route::get('apples/paystack', [AppleController::class, 'paystack'])->name('play.apples.paystack');
    Route::get('apples/paypal', [AppleController::class, 'paypal'])->name('play.apples.paypal');
    Route::get('apples/paypal/cancel', [AppleController::class, 'paypalCancel'])->name('play.apples.paypal-cancel');
    Route::post('apples/purchases/{purchase}/check', [AppleController::class, 'check'])->middleware('throttle:10,1,apple-check')->name('play.apples.check');
    Route::post('apples/swap', [AppleController::class, 'swap'])->name('play.apples.swap');
    Route::post('apples/exchange', [AppleController::class, 'exchange'])->middleware('throttle:30,1,apple-exchange')->name('play.apples.exchange');
    Route::post('trees', [TreeController::class, 'plant'])->middleware('throttle:10,1,trees')->name('play.trees.plant');
    Route::post('trees/harvest', [TreeController::class, 'harvest'])->middleware('throttle:30,1,trees-harvest')->name('play.trees.harvest');
    Route::post('orchard/join', [OrchardController::class, 'join'])->name('play.orchard.join');
    Route::post('orchard/refill', [OrchardController::class, 'refill'])->name('play.orchard.refill');
    Route::get('cashout', [CashoutController::class, 'index'])->name('play.cashout');
    // Each bank account check costs the game R3.
    Route::post('cashout/account', [CashoutController::class, 'account'])->middleware('throttle:10,60,cashout-account')->name('play.cashout.account');
    Route::post('cashout', [CashoutController::class, 'store'])->middleware('throttle:10,1,cashout')->name('play.cashout.request');

    Route::get('me', [ProfileController::class, 'show'])->name('play.me');
    Route::put('me/language', [ProfileController::class, 'language'])->name('play.me.language');

    // The bell, and the pulse every screen checks for pop-ups.
    Route::get('pulse', [NoticeController::class, 'pulse'])->name('play.pulse');
    Route::get('notices', [NoticeController::class, 'index'])->name('play.notices');
    Route::post('notices/read', [NoticeController::class, 'read'])->name('play.notices.read');

    // Private messages between any two animals.
    Route::get('talk/{player}', [MessageController::class, 'talk'])->name('play.talk');
    Route::post('talk/{player}', [MessageController::class, 'say'])->middleware('throttle:20,1,messages')->name('play.talk.say');
    Route::get('messages', [MessageController::class, 'index'])->name('play.messages');
    Route::get('messages/{player}', [MessageController::class, 'show'])->name('play.messages.show');
    Route::post('messages/{player}', [MessageController::class, 'store'])->middleware('throttle:20,1,messages')->name('play.messages.store');
    Route::post('messages/{player}/block', [MessageController::class, 'block'])->name('play.messages.block');
    Route::delete('messages/{player}/block', [MessageController::class, 'unblock'])->name('play.messages.unblock');
    Route::post('message/{message}/translate', [MessageController::class, 'translate'])->middleware('throttle:30,1,message-translate')->name('play.messages.translate');
    Route::post('message/{message}/report', [MessageController::class, 'report'])->middleware('throttle:10,1,message-report')->name('play.messages.report');
});

Route::middleware(['auth', 'verified', 'can:staff'])->prefix('staff')->group(function () {
    Route::get('designs', [DesignController::class, 'index'])->name('staff.designs');
    Route::post('designs/{design}/approve', [DesignController::class, 'approve'])->name('staff.designs.approve');
    Route::post('designs/{design}/reject', [DesignController::class, 'reject'])->name('staff.designs.reject');

    Route::get('reports', [ReportController::class, 'index'])->name('staff.reports');
    Route::post('reports/{report}/dismiss', [ReportController::class, 'dismiss'])->name('staff.reports.dismiss');
    Route::post('reports/{report}/mute', [ReportController::class, 'mute'])->name('staff.reports.mute');
});

// The owner's pages: payment keys, Apple sales and what they brought in, and
// the cash-outs waiting for the owner's approval.
Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])->name('admin');
    Route::put('settings', [SettingsController::class, 'update'])->name('admin.settings.update');
    Route::delete('settings/{key}', [SettingsController::class, 'forget'])->whereIn('key', SettingsController::REMOVABLE)->name('admin.settings.forget');
    Route::post('settings/test/{service}', [SettingsController::class, 'test'])->whereIn('service', ['paystack', 'paypal', 'claude'])->middleware('throttle:10,1,admin-test')->name('admin.settings.test');

    Route::get('cashouts', [CashoutQueueController::class, 'index'])->name('admin.cashouts');
    Route::middleware('throttle:30,1,admin-cashouts')->group(function () {
        Route::post('cashouts/{cashout}/pay', [CashoutQueueController::class, 'pay'])->name('admin.cashouts.pay');
        Route::post('cashouts/{cashout}/check', [CashoutQueueController::class, 'check'])->name('admin.cashouts.check');
        Route::post('cashouts/{cashout}/otp', [CashoutQueueController::class, 'otp'])->name('admin.cashouts.otp');
        Route::post('cashouts/{cashout}/otp/resend', [CashoutQueueController::class, 'resendOtp'])->name('admin.cashouts.resend-otp');
        Route::post('cashouts/{cashout}/reject', [CashoutQueueController::class, 'reject'])->name('admin.cashouts.reject');
    });
});

require __DIR__.'/settings.php';
