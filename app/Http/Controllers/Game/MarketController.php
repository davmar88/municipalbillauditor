<?php

namespace App\Http\Controllers\Game;

use App\Enums\ItemTemplate;
use App\Enums\Study;
use App\Game\LawTemplate;
use App\Game\Market;
use App\Game\Powers;
use App\Game\Research;
use App\Game\Skills;
use App\Game\Trader;
use App\Game\Wall;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Listing;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The market: shops along Market Square, your own shop, and the Workshop. */
class MarketController extends Controller
{
    public function trader(Request $request, Trader $trader): RedirectResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'max:20']]);
        $item = $trader->buy($request->user()->player, $data['template']);

        return $this->told("Pell wraps up {$item->design->name} for you. It's in your bag.");
    }

    public function index(Request $request, Market $market): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $season = $player->valley->currentSeason();
        // The levy goes to the House, so without one nobody charges it.
        $levy = $season && $player->valley->house() !== null ? Wall::law($season, LawTemplate::MarketLevy) : null;
        $ban = $season ? Wall::law($season, LawTemplate::TradeBan) : null;

        $listings = Listing::query()
            ->with(['item.design', 'seller:id,name,species,faction_id,home_closed_until,orchard_until', 'seller.faction:id,name,colour'])
            ->whereNull('sold_at')
            ->whereHas('seller', fn ($query) => $query->where('valley_id', $player->valley_id))
            ->latest('id')
            ->limit(60)
            ->get();

        return Inertia::render('game/market', [
            // Pell the Irongate trader's cart: basics for Corn while the shelves are bare.
            'trader' => Trader::cart($player),
            'listings' => $listings->map(fn (Listing $listing) => [
                'id' => $listing->id,
                'price' => $listing->price,
                'currency' => $listing->currency,
                'serial' => $listing->item->serial,
                'design' => $listing->item->design->toCard(),
                'seller' => [
                    'id' => $listing->seller->id,
                    'name' => $listing->seller->name,
                    'faction' => $listing->seller->faction?->name,
                    'colour' => $listing->seller->faction?->colour,
                    'closed' => $listing->seller->homeIsClosed(),
                    'orchard' => $listing->seller->inOrchard(),
                ],
                'mine' => $listing->player_id === $player->id,
            ]),
            'levy' => $levy?->value ?? 0,
            'bannedFaction' => $ban ? (int) $ban->target : null,
            'items' => $player->items()->with(['design', 'openListing'])->latest('id')->get()->map(fn (Item $item) => [
                'id' => $item->id,
                'serial' => $item->serial,
                'design' => $item->design->toCard(),
                // Someone else's design: a sale pays its maker a royalty.
                'resale' => $item->design->player_id !== $player->id,
                'listing' => $item->openListing ? ['id' => $item->openListing->id, 'price' => $item->openListing->price, 'currency' => $item->openListing->currency] : null,
            ]),
            'designs' => $player->designs()->latest('id')->get()->map(fn (ItemDesign $design) => [
                ...$design->toCard(),
                'needs' => $market->materials($player, $design->template),
            ]),
            'workshop' => [
                'templates' => array_map(fn (ItemTemplate $template) => [
                    'value' => $template->value,
                    'label' => $template->label(),
                    'needs' => $market->materials($player, $template),
                    'power' => $template->power(),
                    'for' => $template->for(),
                    'creativity' => $template->creativity(),
                    // Weapons a faction unlocks by studying Technology, and whether copies may be sold.
                    'technology' => $template->technology(),
                    'sellable' => $template->sellable(),
                ], ItemTemplate::cases()),
                'creativity' => Skills::level((int) $player->creativity_skill),
                'technology' => Research::level($player->faction, Study::Technology),
                'palette' => ItemDesign::PALETTE,
                'patterns' => ItemDesign::PATTERNS,
                'editions' => ItemDesign::EDITIONS,
            ],
            'shop' => [
                'level' => (int) config('game.levels.stall'),
                'shelves' => Market::shelves($player),
                'orchard' => $player->inOrchard(),
                'used' => Market::shelvesUsed($player->id),
                'prices' => [
                    'corn' => Market::priceRange('corn'),
                    'apples' => Market::priceRange('apples'),
                ],
                // Items sell for Apples only.
                'applesOnly' => (bool) config('game.market.apples_only'),
                // Percentages: the game's share of an Apple sale, and a maker's royalty on a resale.
                'cut' => Market::appleCut(),
                'royalty' => (int) round(Market::ROYALTY * 100),
            ],
        ]);
    }

    public function design(Request $request, Market $market): RedirectResponse
    {
        $data = $request->validate([
            'template' => ['required', Rule::enum(ItemTemplate::class)],
            'name' => ['required', 'string', 'max:40'],
            'colours' => ['required', 'array', 'size:2'],
            'colours.*' => ['string'],
            'pattern' => ['required', 'string'],
            'edition' => ['nullable', 'integer'],
        ]);

        $design = $market->design(
            $request->user()->player,
            ItemTemplate::from($data['template']),
            $data['name'],
            $data['colours'],
            $data['pattern'],
            isset($data['edition']) ? (int) $data['edition'] : null,
        );

        return $this->told($design->status === 'approved'
            ? "{$design->name} is ready. Make copies from your materials."
            : "{$design->name} is saved. A new maker's first designs get a quick check before copies can be made.");
    }

    public function craft(Request $request, ItemDesign $design, Market $market): RedirectResponse
    {
        $item = $market->craft($request->user()->player, $design);

        return $this->told("You made {$design->name} #{$item->serial}.");
    }

    public function list(Request $request, Item $item, Market $market): RedirectResponse
    {
        $data = $request->validate([
            'price' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', Rule::in(Market::CURRENCIES)],
            // How many copies of this design to stack on the shelf at that price.
            'count' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $player = $request->user()->player;
        $currency = $data['currency'] ?? (config('game.market.apples_only') ? 'apples' : 'corn');
        $listing = $market->list($player, $item, (int) $data['price'], $currency);
        $listed = 1;

        if (($data['count'] ?? 1) > 1) {
            $more = $player->items()
                ->where('item_design_id', $item->item_design_id)
                ->whereNull('used_at')
                ->whereKeyNot($item->id)
                ->whereDoesntHave('openListing')
                ->orderBy('serial')
                ->limit($data['count'] - 1)
                ->get();

            foreach ($more as $copy) {
                $market->list($player, $copy, (int) $data['price'], $currency);
                $listed++;
            }
        }

        $what = $currency === 'apples' ? 'Apples' : 'Corn';

        return $this->told($listed > 1
            ? "{$listed} on your shelves for {$listing->price} {$what} each."
            : "On your shelves for {$listing->price} {$what}.");
    }

    public function unlist(Request $request, Listing $listing, Market $market): RedirectResponse
    {
        $player = $request->user()->player;
        $market->unlist($player, $listing);

        // The whole stack: every copy of that design at that price.
        if ($request->boolean('all')) {
            $rest = Listing::query()
                ->where('player_id', $player->id)
                ->whereNull('sold_at')
                ->where('price', $listing->price)
                ->where('currency', $listing->currency)
                ->whereHas('item', fn ($query) => $query->where('item_design_id', $listing->item->item_design_id))
                ->get();
            $rest->each(fn (Listing $other) => $market->unlist($player, $other));

            return $this->told(($rest->count() + 1).' taken off your shelves.');
        }

        return $this->told('Taken off your shelves.');
    }

    public function use(Request $request, Item $item, Powers $powers): RedirectResponse
    {
        return $this->told($powers->use($request->user()->player, $item));
    }

    public function buy(Request $request, Listing $listing, Market $market): RedirectResponse
    {
        $result = $market->buy($request->user()->player, $listing);

        if ($result['currency'] === 'apples') {
            return $this->told("Bought for {$result['price']} Apples.");
        }

        $levy = $result['levy'] > 0 ? " plus {$result['levy']} market levy to the House" : '';

        return $this->told("Bought for {$result['price']} Corn{$levy}.");
    }
}
