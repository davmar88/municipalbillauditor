<?php

namespace App\Http\Controllers\Game;

use App\Enums\CrimeKind;
use App\Enums\EntryMode;
use App\Enums\FactionRole;
use App\Enums\Species;
use App\Game\Clock;
use App\Game\Factions;
use App\Http\Controllers\Controller;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\FactionJoinRequest;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Your faction: members, entry rules and requests. Lone wolves see the
 * factions they could join, and the underworld.
 */
class FactionController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $faction = $player->faction;
        $house = $player->valley->house();

        $factions = Faction::query()
            ->where('valley_id', $player->valley_id)
            ->withCount(['members', 'regions'])
            ->orderByDesc('regions_count')
            ->orderBy('name')
            ->get();

        return Inertia::render('game/faction', [
            'faction' => $faction ? [
                ...$this->summary($faction, $house?->id),
                'treasury' => $faction->treasury,
                'members' => $faction->members()->orderByRaw("case faction_role when 'leader' then 0 when 'council' then 1 when 'member' then 2 else 3 end")->orderBy('name')->get()
                    ->map(fn (Player $member) => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'species' => $member->species->value,
                        'level' => $member->level(),
                        'role' => $member->faction_role?->value,
                        'homeClosed' => $member->homeIsClosed(),
                        'me' => $member->is($player),
                    ]),
                'requests' => $player->decides() ? $faction->joinRequests()->with('player')->where('status', 'pending')->get()
                    ->map(fn (FactionJoinRequest $joinRequest) => [
                        'id' => $joinRequest->id,
                        'name' => $joinRequest->player->name,
                        'species' => $joinRequest->player->species->value,
                        'level' => $joinRequest->player->level(),
                        'wanted' => $joinRequest->player->wanted,
                    ]) : [],
            ] : null,
            'myRole' => $player->faction_role?->value,
            'factions' => $factions->map(fn (Faction $other) => [
                ...$this->summary($other, $house?->id),
                'members' => $other->members_count,
                'regions' => $other->regions_count,
                'requested' => FactionJoinRequest::query()->where('faction_id', $other->id)->where('player_id', $player->id)->where('status', 'pending')->exists(),
            ]),
            'founding' => [
                'level' => (int) config('game.levels.found_faction'),
                'corn' => (int) config('game.faction.founding_corn'),
                'colours' => array_values(array_diff(Faction::COLOURS, $factions->pluck('colour')->all())) ?: Faction::COLOURS,
                'maxMembers' => (int) config('game.faction.max_members'),
            ],
            'species' => array_map(fn (Species $species) => ['value' => $species->value, 'label' => $species->plural()], Species::playable()),
            'entryModes' => [
                ['value' => EntryMode::Open->value, 'label' => EntryMode::Open->label()],
                ['value' => EntryMode::Approval->value, 'label' => EntryMode::Approval->label()],
            ],
            'underworld' => $player->isLoneWolf() ? [
                'level' => (int) config('game.levels.crime'),
                'triedToday' => Crime::query()->where('player_id', $player->id)->where('created_at', '>=', Clock::startOfToday())->exists(),
                'wanted' => $player->wanted,
                'crimes' => array_map(fn (CrimeKind $kind) => ['value' => $kind->value, 'label' => $kind->label(), 'horseshoes' => $kind->horseshoes()], CrimeKind::cases()),
                'targets' => Player::query()
                    ->where('valley_id', $player->valley_id)
                    ->whereNotNull('faction_id')
                    ->with('faction:id,name,colour')
                    ->orderBy('name')
                    ->limit(200)
                    ->get()
                    ->map(fn (Player $target) => [
                        'id' => $target->id,
                        'name' => $target->name,
                        'faction' => $target->faction->name,
                        'protected' => $target->isShielded() || $target->homeIsClosed(),
                    ]),
                'history' => Crime::query()->with(['targetFaction:id,name', 'targetPlayer:id,name'])->where('player_id', $player->id)->latest('id')->limit(5)->get()
                    ->map(fn (Crime $crime) => [
                        'id' => $crime->id,
                        'kind' => $crime->kind->value,
                        'target' => $crime->kind === CrimeKind::Loot ? $crime->targetFaction?->name : $crime->targetPlayer?->name,
                        'outcome' => match (true) {
                            $crime->resolved_at === null => 'pending',
                            (bool) $crime->caught => 'caught',
                            default => 'worked',
                        },
                        'loot' => $crime->loot,
                        'settlesAt' => $crime->settles_at->toIso8601String(),
                    ]),
            ] : null,
        ]);
    }

    public function store(Request $request, Factions $factions): RedirectResponse
    {
        $data = $this->validated($request, founding: true);
        $faction = $factions->found($request->user()->player, $data['name'], $data['colour'], $data['motto'] ?? null, $data);

        return $this->told("You founded {$faction->name}. Invite friends: a faction needs ".config('game.faction.members_to_raid').' members to raid.');
    }

    public function join(Request $request, Faction $faction, Factions $factions): RedirectResponse
    {
        $result = $factions->join($request->user()->player, $faction);

        return $this->told($result === 'joined'
            ? "You joined {$faction->name} as a recruit. You get your vote after 48 hours."
            : "You asked to join {$faction->name}. Its leader or council will answer.");
    }

    public function answer(Request $request, FactionJoinRequest $joinRequest, Factions $factions): RedirectResponse
    {
        $accept = $request->validate(['accept' => ['required', 'boolean']])['accept'];
        $factions->answer($request->user()->player, $joinRequest, (bool) $accept);

        return $this->told($accept ? "{$joinRequest->player->name} joined the faction." : 'Request declined.');
    }

    public function leave(Request $request, Factions $factions): RedirectResponse
    {
        $factions->leave($request->user()->player);

        return $this->told('You left your faction. You are a lone wolf now.');
    }

    public function rules(Request $request, Factions $factions): RedirectResponse
    {
        $data = $this->validated($request, founding: false);
        $factions->setRules($request->user()->player, $data, $data['motto'] ?? null);

        return $this->told('The faction rules are updated.');
    }

    public function role(Request $request, Player $member, Factions $factions): RedirectResponse
    {
        $role = $request->validate(['role' => ['required', Rule::enum(FactionRole::class)]])['role'];
        $factions->setRole($request->user()->player, $member, FactionRole::from($role));

        return $this->told("{$member->name} is now ".FactionRole::from($role)->label().'.');
    }

    public function remove(Request $request, Player $member, Factions $factions): RedirectResponse
    {
        $factions->remove($request->user()->player, $member);

        return $this->told("{$member->name} was removed from the faction.");
    }

    /** @return array<string, mixed> */
    private function summary(Faction $faction, ?int $houseId): array
    {
        return [
            'id' => $faction->id,
            'name' => $faction->name,
            'colour' => $faction->colour,
            'motto' => $faction->motto,
            'isHouse' => $faction->id === $houseId,
            'rules' => [
                'entryMode' => $faction->entry_mode->value,
                'minLevel' => $faction->min_level,
                'species' => $faction->species,
                'entryFee' => $faction->entry_fee,
                'acceptsCriminals' => $faction->accepts_criminals,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $founding): array
    {
        return $request->validate([
            'name' => [Rule::requiredIf($founding), 'string', 'max:40'],
            'colour' => [Rule::requiredIf($founding), 'string', 'max:9'],
            'motto' => ['nullable', 'string', 'max:80'],
            'entry_mode' => ['nullable', Rule::enum(EntryMode::class)],
            'min_level' => ['nullable', 'integer', 'between:1,40'],
            'species' => ['nullable', 'array'],
            'species.*' => [Rule::enum(Species::class)],
            'entry_fee' => ['nullable', 'integer', 'between:0,1000'],
            'accepts_criminals' => ['nullable', 'boolean'],
        ]);
    }
}
