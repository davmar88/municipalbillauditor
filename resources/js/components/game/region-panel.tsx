import { Link, usePage } from '@inertiajs/react';
import {
    Crown,
    Footprints,
    Hammer,
    ScrollText,
    Shield,
    Store,
    Swords,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { BuildingDetails, BuildOptions } from '@/components/game/faction-base';
import { Horseshoe } from '@/components/game/game-bar';
import { HorseshoePicker } from '@/components/game/horseshoe-picker';
import { Button } from '@/components/ui/button';
import { act, capitalise, clockTime, fromNow, speciesEmoji } from '@/lib/game';
import { route } from '@/lib/hollowmere';
import {
    attack,
    commit,
    faction as factionPage,
    gate,
    gather,
    market,
    walk,
    wall,
} from '@/routes/play';
import type {
    BuildingKindCard,
    FactionRef,
    FactionStore,
    MapBattle,
    MapRegion,
} from '@/types/game';

const RINGS: Record<MapRegion['ring'], string> = {
    seat: 'Seat of power',
    inner: 'Inner ring',
    middle: 'Middle ring',
    outer: 'Outer ring',
    safe: 'Safe ground',
};

type Props = {
    region: MapRegion;
    regions: MapRegion[];
    here: number | null;
    house: FactionRef | null;
    battles: MapBattle[];
    yields: { corn: number; material: number };
    gateNight: { open: boolean; defended: boolean };
    base: FactionStore | null;
    buildingKinds: BuildingKindCard[];
    onClose: () => void;
};

/**
 * The card for the region you tapped: who holds it, who stands there, and
 * what you can do from where you are. The rules are the server's; the card
 * only explains them.
 */
export function RegionPanel({
    region,
    regions,
    here,
    house,
    battles,
    yields,
    gateNight,
    base,
    buildingKinds,
    onClose,
}: Props) {
    const { player } = usePage().props;
    const [horseshoes, setHorseshoes] = useState(1);
    const byId = useMemo(
        () => new Map(regions.map((other) => [other.id, other])),
        [regions],
    );
    const graph = useMemo(
        () => new Map(regions.map((other) => [other.id, other.neighbours])),
        [regions],
    );

    const myFaction = player?.faction ?? null;
    const holds = (other?: MapRegion) =>
        myFaction !== null && other?.faction?.id === myFaction.id;
    const hereRegion = here !== null ? byId.get(here) : undefined;
    const isHere = region.id === here;
    const path =
        here !== null && !isHere ? route(here, region.id, graph) : null;
    const next = path && path.length > 1 ? byId.get(path[1]) : undefined;
    const walkIsFree = holds(hereRegion) && holds(next);
    const frontLine = here !== null && region.neighbours.includes(here);
    const raidable =
        myFaction !== null && region.ring !== 'safe' && !holds(region);
    const others = region.animals.sample.filter((animal) => !animal.me);
    const battleFor = new Map(battles.map((battle) => [battle.id, battle]));
    const isHouse = house !== null && region.faction?.id === house.id;
    const buildable =
        holds(region) &&
        region.ring !== 'safe' &&
        region.key !== 'farmhouse' &&
        region.building === null;

    return (
        <section
            aria-label={region.name}
            className="absolute inset-x-2 bottom-2 z-10 flex max-h-[56%] flex-col overflow-hidden rounded-2xl border bg-card/95 shadow-2xl backdrop-blur md:inset-x-auto md:bottom-4 md:left-4 md:max-h-[calc(100%-2rem)] md:w-[22rem]"
        >
            <header className="flex items-start gap-3 border-b px-4 py-3">
                <div className="min-w-0 flex-1">
                    <p className="text-[11px] font-bold tracking-widest text-muted-foreground uppercase">
                        {RINGS[region.ring]}
                        {region.produces &&
                            ` · ${region.produces === 'corn' ? 'Corn' : capitalise(region.produces)}`}
                    </p>
                    <h2 className="truncate text-xl font-extrabold tracking-tight">
                        {region.name}
                    </h2>
                    <p className="mt-0.5 flex items-center gap-1.5">
                        {region.faction ? (
                            <>
                                {isHouse ? (
                                    <Crown
                                        className="size-4 shrink-0 text-primary"
                                        aria-hidden
                                    />
                                ) : (
                                    <span
                                        className="size-2.5 shrink-0 rounded-full"
                                        style={{
                                            background: region.faction.colour,
                                        }}
                                    />
                                )}
                                <span>
                                    Held by{' '}
                                    <span
                                        className="font-bold"
                                        style={{ color: region.faction.colour }}
                                    >
                                        {region.faction.name}
                                    </span>
                                    {isHouse && ', the House'}
                                    {holds(region) && ' (yours)'}
                                </span>
                            </>
                        ) : region.ring === 'safe' ? (
                            'Nobody can be raided here.'
                        ) : region.key === 'farmhouse' ? (
                            'Nobody rules. Whoever takes it becomes the House.'
                        ) : (
                            <span className="text-muted-foreground">
                                Nobody holds it yet.
                            </span>
                        )}
                    </p>
                </div>
                <button
                    type="button"
                    aria-label="Close"
                    onClick={onClose}
                    className="-mr-1 flex size-9 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <X className="size-4" />
                </button>
            </header>

            <div className="flex flex-col gap-3 overflow-y-auto px-4 py-3 text-sm">
                {region.incoming.map((incoming) => {
                    const battle = battleFor.get(incoming.id);

                    return (
                        <div
                            key={incoming.id}
                            className="flex flex-col gap-2 rounded-lg border border-orange-500/40 bg-orange-500/10 p-3"
                        >
                            <p className="flex items-start gap-2">
                                <Swords
                                    className="mt-0.5 size-4 shrink-0 text-orange-300"
                                    aria-hidden
                                />
                                <span>
                                    <span
                                        className="font-bold"
                                        style={{
                                            color: incoming.faction.colour,
                                        }}
                                    >
                                        {incoming.faction.name}
                                    </span>{' '}
                                    is marching here. The raid lands at{' '}
                                    {clockTime(incoming.landsAt)} (
                                    {fromNow(incoming.landsAt)}).
                                </span>
                            </p>
                            {battle &&
                                (battle.joined ? (
                                    <p className="font-semibold text-primary">
                                        You're in this battle.
                                    </p>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <HorseshoePicker
                                            value={horseshoes}
                                            onChange={setHorseshoes}
                                        />
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                act(commit.url(battle.id), {
                                                    horseshoes,
                                                })
                                            }
                                        >
                                            {battle.side === 'attack'
                                                ? 'Join the attack'
                                                : 'Defend'}
                                        </Button>
                                    </div>
                                ))}
                        </div>
                    );
                })}

                {region.settledUntil && (
                    <p className="flex items-start gap-2 text-muted-foreground">
                        <Shield
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden
                        />
                        It just changed hands. Raids open again at{' '}
                        {clockTime(region.settledUntil)}.
                    </p>
                )}

                {region.animals.count > 0 && (
                    <div className="flex flex-col gap-1.5">
                        <p className="text-muted-foreground">
                            {region.animals.count === 1
                                ? '1 animal is'
                                : `${region.animals.count} animals are`}{' '}
                            here
                            {isHere &&
                                region.animals.count > 1 &&
                                ', you included'}
                            {isHere && region.animals.count === 1 && ': you'}.
                        </p>
                        {others.length > 0 && (
                            <ul className="flex flex-wrap gap-1.5">
                                {others.slice(0, 8).map((animal, i) => (
                                    <li
                                        key={`${animal.name}-${i}`}
                                        className="flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
                                        style={{
                                            borderColor:
                                                animal.colour ?? undefined,
                                        }}
                                    >
                                        <span aria-hidden>
                                            {speciesEmoji(animal.species)}
                                        </span>
                                        {animal.name}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {isHere ? (
                    <div className="flex flex-col gap-2 rounded-lg border border-primary/40 bg-primary/10 p-3">
                        <p className="font-semibold text-primary">
                            You're standing here.
                        </p>
                        {region.produces ? (
                            <Button onClick={() => act(gather.url(region.id))}>
                                Gather{' '}
                                {region.produces === 'corn'
                                    ? `${yields.corn} Corn`
                                    : `${yields.material} ${region.produces}`}
                                <span className="ml-auto flex items-center gap-1 opacity-80">
                                    <Horseshoe className="size-4" />1
                                </span>
                            </Button>
                        ) : (
                            <p className="text-muted-foreground">
                                Nothing grows here. You gather in fields, woods
                                and yards.
                            </p>
                        )}
                    </div>
                ) : (
                    path &&
                    next && (
                        <div className="flex flex-col gap-2">
                            {path.length > 2 && (
                                <p className="text-muted-foreground">
                                    {path.length - 1} walks away, by way of{' '}
                                    {path
                                        .slice(1, -1)
                                        .map((id) => byId.get(id)?.name)
                                        .join(', ')}
                                    .
                                </p>
                            )}
                            <Button
                                variant={
                                    path.length === 2 ? 'default' : 'secondary'
                                }
                                onClick={() => act(walk.url(next.id))}
                            >
                                <Footprints className="size-4" />
                                {path.length === 2
                                    ? 'Walk here'
                                    : `Walk to ${next.name}`}
                                <span className="ml-auto flex items-center gap-1 opacity-80">
                                    {walkIsFree ? (
                                        'free on your land'
                                    ) : (
                                        <>
                                            <Horseshoe className="size-4" />1
                                        </>
                                    )}
                                </span>
                            </Button>
                        </div>
                    )
                )}

                {raidable && (
                    <div className="flex flex-col gap-2 rounded-lg border p-3">
                        <p className="flex items-center gap-2 font-semibold">
                            <Swords
                                className="size-4 text-primary"
                                aria-hidden
                            />{' '}
                            Raid {region.name}
                        </p>
                        {region.settledUntil ? (
                            <p className="text-muted-foreground">
                                Wait until {clockTime(region.settledUntil)}.
                            </p>
                        ) : frontLine ? (
                            <>
                                <p className="text-muted-foreground">
                                    The raid marches for an hour. Put in
                                    horseshoes; your faction can add more.
                                </p>
                                <div className="flex items-center gap-2">
                                    <HorseshoePicker
                                        value={horseshoes}
                                        onChange={setHorseshoes}
                                    />
                                    <Button
                                        onClick={() =>
                                            act(attack.url(region.id), {
                                                horseshoes,
                                            })
                                        }
                                    >
                                        <Swords className="size-4" /> Raid
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                Raids start from the front line: walk to a
                                region next to {region.name} first.
                            </p>
                        )}
                        {region.key === 'farmhouse' && (
                            <p className="text-xs text-muted-foreground">
                                To take the Farmhouse your faction must hold 2
                                of the 4 regions around it.
                            </p>
                        )}
                    </div>
                )}

                {!myFaction && region.ring !== 'safe' && (
                    <p className="text-muted-foreground">
                        Join a faction to raid. Lone wolves loot and burn
                        instead, from the{' '}
                        <Link
                            href={factionPage()}
                            className="font-semibold text-primary"
                        >
                            Faction
                        </Link>{' '}
                        tab.
                    </p>
                )}

                {region.building && region.faction && (
                    <div className="flex flex-col gap-2 rounded-lg border p-3">
                        <p className="flex items-center gap-2 font-semibold">
                            <Hammer
                                className="size-4"
                                style={{ color: region.faction.colour }}
                                aria-hidden
                            />
                            {region.faction.name}'s {region.building.label}
                        </p>
                        <BuildingDetails
                            building={region.building}
                            kind={buildingKinds.find(
                                (kind) => kind.value === region.building?.kind,
                            )}
                            store={holds(region) ? base : null}
                            canBuild={
                                holds(region) && (base?.canBuild ?? false)
                            }
                            hasDen={base?.hasDen ?? false}
                        />
                    </div>
                )}

                {buildable && base?.canBuild && (
                    <div className="flex flex-col gap-2 rounded-lg border p-3">
                        <p className="flex items-center gap-2 font-semibold">
                            <Hammer
                                className="size-4 text-primary"
                                aria-hidden
                            />
                            Build here
                        </p>
                        <BuildOptions
                            regionId={region.id}
                            kinds={buildingKinds}
                            store={base}
                            hasDen={base.hasDen}
                        />
                    </div>
                )}

                {buildable && base && !base.canBuild && (
                    <p className="text-muted-foreground">
                        Your leader and council can raise a building here. Give
                        materials to the store from the{' '}
                        <Link
                            href={factionPage()}
                            className="font-semibold text-primary"
                        >
                            Faction
                        </Link>{' '}
                        tab.
                    </p>
                )}

                {region.key === 'big_barn' && (
                    <Link
                        href={wall()}
                        className="flex items-center gap-2 font-semibold text-primary"
                    >
                        <ScrollText className="size-4" aria-hidden /> The Barn
                        Wall is here. Read the laws.
                    </Link>
                )}

                {region.key === 'market_square' && (
                    <Link
                        href={market()}
                        className="flex items-center gap-2 font-semibold text-primary"
                    >
                        <Store className="size-4" aria-hidden /> Go to the
                        market stalls.
                    </Link>
                )}

                {region.key === 'ironfield_gate' && gateNight.open && (
                    <div className="flex flex-col gap-2 rounded-lg border border-orange-500/40 bg-orange-500/10 p-3">
                        <p className="font-semibold">Gate Night is tonight.</p>
                        <p>
                            Ironfield's men raid at 19:00. If the gate holds,
                            every defender earns 15 Rosettes; if not, two outer
                            fields burn.
                        </p>
                        {gateNight.defended ? (
                            <p className="font-semibold text-primary">
                                You're at the gate.
                            </p>
                        ) : (
                            <div className="flex items-center gap-2">
                                <HorseshoePicker
                                    value={horseshoes}
                                    onChange={setHorseshoes}
                                />
                                <Button
                                    onClick={() =>
                                        act(gate.url(), { horseshoes })
                                    }
                                >
                                    Hold the gate
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
