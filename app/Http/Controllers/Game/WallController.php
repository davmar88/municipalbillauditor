<?php

namespace App\Http\Controllers\Game;

use App\Enums\FactionRole;
use App\Enums\Species;
use App\Game\Clock;
use App\Game\LawTemplate;
use App\Game\Uprisings;
use App\Game\Wall;
use App\Http\Controllers\Controller;
use App\Models\Faction;
use App\Models\Law;
use App\Models\Player;
use App\Models\Uprising;
use App\Models\WallCopy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The Barn Wall: the laws, unrest, secret edits, festivals and uprisings. */
class WallController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $season = $player->valley->currentSeason();
        abort_if($season === null, 503);

        $house = $player->valley->house();
        $isHouse = $house !== null && $player->faction_id === $house->id;
        $laws = Wall::laws($season)->keyBy('slot');
        $copy = Wall::latestCopy($player, $season);
        $uprising = Uprisings::active($season);
        $changesToday = $season->lawVersions()->where('secret', false)->whereNotNull('player_id')->where('created_at', '>=', Clock::startOfToday())->count();
        $unrest = Wall::unrest($season);

        return Inertia::render('game/wall', [
            // A secret law shows as an empty place to those without eyes to see it.
            'laws' => collect(range(1, Wall::SLOTS))->map(fn (int $slot) => ($law = $laws->get($slot)) && Wall::canSee($player, $law) ? $this->law($law) : ['slot' => $slot]),
            'templates' => array_map(fn (LawTemplate $template) => [
                'value' => $template->value,
                'label' => $template->label(),
                'explains' => $template->explains(),
                'range' => $template->range(),
                'unit' => $template->unit(),
                'target' => $template->target(),
                'default' => $template->defaultValue(),
            ], LawTemplate::cases()),
            'unrest' => $unrest,
            'unrestEvents' => $season->unrestEvents()->where('expires_at', '>', CarbonImmutable::now())->where('reason', 'not like', 'chant:%')->latest('id')->limit(5)->get()
                ->map(fn ($event) => ['reason' => $event->reason, 'amount' => $event->amount, 'until' => $event->expires_at->toIso8601String()]),
            'uprisingAt' => Wall::UPRISING_AT,
            'risesAt' => Wall::RISES_ALONE_AT,
            'house' => $house ? ['id' => $house->id, 'name' => $house->name, 'colour' => $house->colour, 'leader' => $house->leader()?->name] : null,
            'canDecide' => $isHouse && $player->decides(),
            'isLeader' => $isHouse && $player->faction_role === FactionRole::Leader,
            'changesLeft' => max(0, Wall::CHANGES_PER_DAY + $season->bonus_law_changes - $changesToday),
            'secretEdit' => [
                'windowOpen' => Clock::hourIn(CarbonImmutable::now(), 0, 4),
                'usedThisWeek' => $season->last_secret_edit_at !== null && $season->last_secret_edit_at->gt(CarbonImmutable::now()->subDays(7)),
            ],
            'copy' => $copy ? ['at' => $copy->created_at->toIso8601String(), 'laws' => $copy->laws] : null,
            'canRemember' => ! WallCopy::query()->where('player_id', $player->id)->where('created_at', '>=', Clock::startOfToday())->exists(),
            'goatHints' => Wall::goatHints($player, $season),
            'history' => Wall::history($season),
            'uprising' => $uprising ? $this->uprising($uprising, $player, $isHouse) : null,
            'canCallUprising' => $house !== null && ! $isHouse && $uprising === null && $unrest >= Wall::UPRISING_AT,
            'festivalReady' => $season->last_festival_at === null || $season->last_festival_at->lte(CarbonImmutable::now()->subDays(3)),
            'canChant' => $player->species === Species::Sheep && $player->faction?->motto !== null && $house !== null,
            'factions' => Faction::query()->where('valley_id', $player->valley_id)->orderBy('name')->get(['id', 'name', 'colour']),
            'species' => array_map(fn (Species $species) => ['value' => $species->value, 'label' => $species->plural()], Species::cases()),
        ]);
    }

    public function setLaw(Request $request, Wall $wall): RedirectResponse
    {
        $data = $request->validate([
            'slot' => ['required', 'integer', 'between:1,'.Wall::SLOTS],
            'template' => ['nullable', Rule::enum(LawTemplate::class)],
            'value' => ['nullable', 'integer'],
            'target' => ['nullable', 'string', 'max:20'],
        ]);

        $law = $wall->setLaw(
            $request->user()->player,
            (int) $data['slot'],
            isset($data['template']) ? LawTemplate::from($data['template']) : null,
            isset($data['value']) ? (int) $data['value'] : null,
            $data['target'] ?? null,
        );

        return $this->told($law ? "Painted on the Wall: {$law->wording()}" : 'That law was painted over.');
    }

    public function secretEdit(Request $request, Wall $wall): RedirectResponse
    {
        $data = $request->validate([
            'slot' => ['required', 'integer', 'between:1,'.Wall::SLOTS],
            'value' => ['required', 'integer'],
        ]);

        $wall->secretEdit($request->user()->player, (int) $data['slot'], (int) $data['value']);

        return $this->told('Done. Nobody was told. Anyone with an older copy of the Wall has 72 hours to notice.');
    }

    public function remember(Request $request, Wall $wall): RedirectResponse
    {
        $wall->remember($request->user()->player);

        return $this->told('You saved a dated copy of the Wall. Compare it with the Wall over the next days.');
    }

    public function expose(Request $request, Wall $wall): RedirectResponse
    {
        $slot = (int) $request->validate(['slot' => ['required', 'integer', 'between:1,'.Wall::SLOTS]])['slot'];
        $result = $wall->expose($request->user()->player, $slot);

        if (! $result['correct']) {
            return $this->told("Your copy doesn't prove a secret change to that law. That cost you 2 horseshoes.", 'warning');
        }

        return $this->told($result['rank'] === 1
            ? "Caught it! You exposed the House's secret edit and earned {$result['rosettes']} Rosettes. The valley is angry."
            : "You confirmed the secret edit and earned {$result['rosettes']} Rosettes.");
    }

    public function festival(Request $request, Wall $wall): RedirectResponse
    {
        $wall->festival($request->user()->player);

        return $this->told('The House held a festival. The valley is calmer for two days.');
    }

    public function chant(Request $request, Wall $wall): RedirectResponse
    {
        $amount = $wall->chant($request->user()->player);

        return $this->told($amount < 0 ? 'Your chant calmed the valley a little.' : 'Your chant stirred the valley up a little.');
    }

    public function callUprising(Request $request, Uprisings $uprisings): RedirectResponse
    {
        $horseshoes = (int) $request->validate(['horseshoes' => ['required', 'integer', 'between:1,3']])['horseshoes'];
        $uprising = $uprisings->call($request->user()->player, $horseshoes);

        return $this->told("You called an uprising. It needs {$uprising->pledges_needed} rebels within two hours. Spread the word.");
    }

    public function pledge(Request $request, Uprising $uprising, Uprisings $uprisings): RedirectResponse
    {
        $data = $request->validate([
            'side' => ['required', Rule::in(['rebel', 'loyal'])],
            'horseshoes' => ['required', 'integer', 'between:1,3'],
        ]);

        $uprisings->pledge($request->user()->player, $uprising, $data['side'], (int) $data['horseshoes']);

        return $this->told($data['side'] === 'rebel' ? 'You pledged to the rebels.' : 'You stand with the House.');
    }

    /** @return array<string, mixed> */
    private function law(Law $law): array
    {
        return [
            'slot' => $law->slot,
            'template' => $law->template->value,
            'label' => $law->template->label(),
            'value' => $law->value,
            'target' => $law->target,
            'wording' => $law->wording(),
            'unrest' => $law->unrest(),
            'secret' => $law->template->secret(),
            'expiresAt' => $law->expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function uprising(Uprising $uprising, Player $player, bool $isHouse): array
    {
        $pledges = $uprising->pledges();

        return [
            'id' => $uprising->id,
            'status' => $uprising->status,
            'pledgesNeeded' => $uprising->pledges_needed,
            'rebels' => (clone $pledges)->where('side', 'rebel')->count(),
            'loyalists' => (clone $pledges)->where('side', 'loyal')->count(),
            'gatheringUntil' => $uprising->gathering_until->toIso8601String(),
            'closesAt' => $uprising->closes_at?->toIso8601String(),
            'myPledge' => (clone $pledges)->where('player_id', $player->id)->value('side'),
            // The House's own animals can only stand with it, once it opens.
            'houseMember' => $isHouse,
        ];
    }
}
