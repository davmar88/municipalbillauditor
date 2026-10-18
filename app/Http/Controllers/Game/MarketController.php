<?php

namespace App\Http\Controllers\Game;

use App\Enums\ItemTemplate;
use App\Game\LawTemplate;
use App\Game\Market;
use App\Game\Powers;
use App\Game\Skills;
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
                ], ItemTemplate::cases()),
                'creativity' => Skills::level((int) $player->creativity_skill),
                'palette' => ItemDesign::PALETTE,
                'patterns' => ItemDesign::PATTERNS,
                'editions' => ItemDesign::EDITIONS,
            ],
            'shop' => [
                'level' => (int) config('game.levels.stall'),
                'shelves' => Market::shelves($player),
                'orchard' => $player->inOrchard(),
                'used' => Listing::query()->where('player_id', $player->id)->whereNull('sold_at')->count(),
                'prices' => [
                    'corn' => Market::priceRange('corn'),
                    'apples' => Market::priceRange('apples'),
                ],
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
        ]);
        $listing = $market->list($request->user()->player, $item, (int) $data['price'], $data['currency'] ?? 'corn');

        return $this->told("On your shelves for {$listing->price} ".($listing->currency === 'apples' ? 'Apples.' : 'Corn.'));
    }

    public function unlist(Request $request, Listing $listing, Market $market): RedirectResponse
    {
        $market->unlist($request->user()->player, $listing);

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
