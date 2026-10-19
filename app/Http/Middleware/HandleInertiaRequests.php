<?php

namespace App\Http\Middleware;

use App\Game\Bases;
use App\Game\Horseshoes;
use App\Game\Levels;
use App\Game\Messages;
use App\Game\Missions;
use App\Game\Notices;
use App\Game\Orchard;
use App\Game\Powers;
use App\Game\Stores;
use App\Models\Cashout;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // The bar along the top of every game screen.
            'player' => function () use ($request) {
                $player = $request->user()?->player?->fresh(['faction']);

                if ($player === null) {
                    return null;
                }

                $level = $player->level();

                return [
                    'id' => $player->id,
                    'name' => $player->name,
                    'species' => $player->species->value,
                    'level' => $level,
                    'rosettes' => $player->rosettes,
                    'nextLevelAt' => Levels::threshold($level + 1),
                    'horseshoes' => Horseshoes::current($player),
                    'maxHorseshoes' => Horseshoes::max(),
                    'nextHorseshoeIn' => Horseshoes::nextIn($player),
                    'corn' => $player->corn,
                    'apples' => (int) $player->apples,
                    'pineapples' => (int) $player->pineapples,
                    'orchard' => $player->inOrchard(),
                    'freeRefill' => Orchard::refillReady($player),
                    'materials' => $player->materials,
                    'language' => $player->language,
                    'faction' => $player->faction ? [
                        'id' => $player->faction->id,
                        'name' => $player->faction->name,
                        'colour' => $player->faction->colour,
                        'role' => $player->faction_role?->value,
                        // The faction's own strip under yours: its treasury, its store and what it's short of.
                        'treasury' => $player->faction->treasury,
                        'store' => array_filter($player->faction->materials ?? [], fn ($amount) => $amount > 0),
                        'needs' => Bases::needs($player->faction),
                        // How much the store holds at most: Corn, and of each material.
                        'capacity' => Stores::capacity($player->faction),
                    ] : null,
                    'inPoundUntil' => $player->inPound() ? $player->pound_until->toIso8601String() : null,
                    'homeClosedUntil' => $player->homeIsClosed() ? $player->home_closed_until->toIso8601String() : null,
                    'shieldUntil' => $player->isShielded() ? $player->shield_until->toIso8601String() : null,
                    'powers' => Powers::summary($player),
                ];
            },
            // The badge on Messages. Sent with every reload, so pages that
            // poll keep it fresh too.
            'unreadMessages' => Inertia::always(function () use ($request) {
                $player = $request->user()?->player;

                return $player !== null ? Messages::unread($player) : 0;
            }),
            // The badge on the bell.
            'unreadNotices' => Inertia::always(function () use ($request) {
                $player = $request->user()?->player;

                return $player !== null ? Notices::unread($player) : 0;
            }),
            'isStaff' => fn () => (bool) $request->user()?->is_staff,
            'isAdmin' => fn () => (bool) $request->user()?->is_admin,
            // The badge on the owner's Cash-outs page.
            'cashoutsWaiting' => fn () => $request->user()?->is_admin ? Cashout::query()->where('status', 'pending')->count() : 0,
            // Finished missions waiting to be collected, for the Today badge.
            'missionsWaiting' => fn () => $request->user()?->player ? Missions::waiting($request->user()->player) : 0,
            // The story step you're on, for the Next step bar under the game bar.
            'nextStep' => fn () => $request->user()?->player ? Missions::current($request->user()->player) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
