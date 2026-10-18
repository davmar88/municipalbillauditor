import { Link, usePage } from '@inertiajs/react';
import {
    Crosshair,
    Crown,
    Flame,
    Gavel,
    Footprints,
    Hammer,
    MessageCircle,
    ScrollText,
    Shield,
    Store,
    Sprout,
    Swords,
    Wheat,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    BuildingDetails,
    BuildOptions,
    CostList,
    shortOf,
} from '@/components/game/faction-base';
import { Horseshoe } from '@/components/game/game-bar';
import { HorseshoePicker } from '@/components/game/horseshoe-picker';
import { ProfileLink } from '@/components/game/profile-link';
import { Button } from '@/components/ui/button';
import { act, capitalise, clockTime, fromNow, speciesEmoji } from '@/lib/game';
import { route } from '@/lib/hollowmere';
import { openChat } from '@/lib/pulse';
import { GateStrength } from '@/components/game/gate-strength';
import { arrest as arrestOutlaw } from '@/routes/play/warrant';
import {
    attack,
    buyFarm,
    growFarm,
    commit,
    crime,
    faction as factionPage,
    gate,
    gather,
    market,
    walk,
    wall,
} from '@/routes/play';
import type {
    GateNightInfo,
    BuildingKindCard,
    FactionRef,
    FactionStore,
    MapBattle,
    MapRegion,
} from '@/types/game';

/** An open warrant, as the map knows it. */
export type Warrant = {
    id: number;
    playerId: number;
    /** In Apples. */
    bounty: number;
    escapedUntil: string | null;
};

/** What plundering a farm section carries off, and what it takes. */
export type Plunder = {
    crop: number;
    corn: number;
    level: number;
    horseshoes: number;
};

const RINGS: Record<MapRegion['ring'], string> = {
    seat: 'Seat of power',
    inner: 'Inner ring',
    middle: 'Middle ring',
    outer: 'Outer ring',
    safe: 'Safe ground',
    farm: 'Farm beyond the fences',
};

type Props = {
    region: MapRegion;
    regions: MapRegion[];
    here: number | null;
    house: FactionRef | null;
    battles: MapBattle[];
    yields: { corn: number; material: number };
    gateNight: GateNightInfo;
    base: FactionStore | null;
    buildingKinds: BuildingKindCard[];
    farms: { price: number; income: number };
    warrants: Warrant[];
    plunder: Plunder;
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
    farms,
    warrants,
    plunder,
    onClose,
}: Props) {
    const { player } = usePage().props;
    const horseshoesLeft = player?.horseshoes ?? 0;
    const nextHorseshoe =
        player?.nextHorseshoeIn != null
            ? new Date(Date.now() + player.nextHorseshoeIn * 1000).toISOString()
            : null;
    const outOfHorseshoes = (
        <p className="text-xs text-orange-300">
            You're out of horseshoes.
            {nextHorseshoe && ` The next one comes ${fromNow(nextHorseshoe)}.`}
        </p>
    );
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
    const isHere = region.id === here;
    const path =
        here !== null && !isHere ? route(here, region.id, graph) : null;
    const next = path && path.length > 1 ? byId.get(path[1]) : undefined;
    const frontLine = here !== null && region.neighbours.includes(here);
    const raidable =
        myFaction !== null && region.ring !== 'safe' && !holds(region);
    const others = region.animals.sample.filter((animal) => !animal.me);
    const outlaws = others.flatMap((animal) => {
        const warrant = warrants.find((w) => w.playerId === animal.id);

        return warrant ? [{ animal, warrant }] : [];
    });
    const plunderable =
        myFaction === null && region.ring === 'farm' && region.faction !== null;
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
                                        className="flex items-center rounded-full border text-xs"
                                        style={{
                                            borderColor:
                                                animal.colour ?? undefined,
                                        }}
                                    >
                                        <ProfileLink
                                            animal={animal}
                                            className="flex items-center gap-1 rounded-l-full py-0.5 pr-1 pl-2 hover:bg-accent hover:no-underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            <span aria-hidden>
                                                {speciesEmoji(animal.species)}
                                            </span>
                                            {animal.name}
                                        </ProfileLink>
                                        <button
                                            type="button"
                                            onClick={() => openChat(animal.id)}
                                            className="flex items-center self-stretch rounded-r-full pr-2 pl-1 text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            aria-label={`Talk to ${animal.name}`}
                                            title={`Talk to ${animal.name}`}
                                        >
                                            <MessageCircle
                                                className="size-3"
                                                aria-hidden
                                            />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {outlaws.map(({ animal, warrant }) => (
                    <div
                        key={warrant.id}
                        className="flex flex-col gap-2 rounded-lg border border-orange-500/50 bg-orange-500/10 p-3"
                    >
                        <p className="flex items-center gap-2 font-semibold">
                            <Crosshair
                                className="size-4 text-orange-300"
                                aria-hidden
                            />
                            {animal.name} is wanted: a bounty of{' '}
                            {warrant.bounty}{' '}
                            {warrant.bounty === 1 ? 'Apple' : 'Apples'}
                        </p>
                        {isHere ? (
                            warrant.escapedUntil ? (
                                <p className="text-muted-foreground">
                                    They slipped away. Back on the trail at{' '}
                                    {clockTime(warrant.escapedUntil)}.
                                </p>
                            ) : (
                                <Button
                                    className="self-start"
                                    onClick={() =>
                                        act(arrestOutlaw.url(warrant.id))
                                    }
                                >
                                    <Gavel className="size-4" /> Arrest{' '}
                                    {animal.name} (2 horseshoes)
                                </Button>
                            )
                        ) : (
                            <p className="text-muted-foreground">
                                Walk here to try an arrest.
                            </p>
                        )}
                    </div>
                ))}

                {region.stock && region.produces && (
                    <StockLine
                        stock={region.stock}
                        produces={region.produces}
                    />
                )}

                {isHere ? (
                    <div className="flex flex-col gap-2 rounded-lg border border-primary/40 bg-primary/10 p-3">
                        <p className="font-semibold text-primary">
                            You're standing here.
                        </p>
                        {region.produces ? (
                            <>
                                <Button
                                    disabled={
                                        horseshoesLeft < 1 ||
                                        region.stock?.left === 0
                                    }
                                    onClick={() => act(gather.url(region.id))}
                                >
                                    Gather{' '}
                                    {region.produces === 'corn'
                                        ? `${yields.corn} Corn`
                                        : `${yields.material} ${region.produces}`}
                                    <span className="ml-auto flex items-center gap-1 opacity-80">
                                        <Horseshoe className="size-4" />1
                                    </span>
                                </Button>
                                {horseshoesLeft < 1 && outOfHorseshoes}
                            </>
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
                                <span className="ml-auto opacity-80">free</span>
                            </Button>
                        </div>
                    )
                )}

                {region.ring === 'farm' && (
                    <div className="flex flex-col gap-2 rounded-lg border border-yellow-400/40 bg-yellow-400/10 p-3">
                        <p className="flex items-center gap-2 font-semibold">
                            <Wheat
                                className="size-4 text-yellow-300"
                                aria-hidden
                            />
                            {region.faction
                                ? `${region.faction.name} owns this ${region.partOf ? 'land' : 'farm'}`
                                : `This ${region.partOf ? 'land' : 'farm'} is for sale`}
                        </p>
                        {region.partOf && (
                            <p className="text-muted-foreground">
                                Part of {region.partOf}, cleared from the wild
                                land round it.
                            </p>
                        )}
                        <p>
                            It grows{' '}
                            <span className="font-semibold">
                                {region.produces}
                            </span>
                            , which nowhere in Hollowmere does, for new recipes
                            in the Workshop.{' '}
                            {region.faction
                                ? `Only ${region.faction.name} can gather it now.`
                                : 'Anyone can gather it until a faction claims the farm.'}
                        </p>
                        <p className="text-muted-foreground">
                            It pays {farms.income} Corn into its holder's
                            treasury every half hour.{' '}
                            {region.faction
                                ? 'Rivals take it in a raid from a region next to it.'
                                : `Your faction can buy it for ${farms.price} Corn from the treasury, or raid for it.`}
                        </p>
                        {!region.faction &&
                            (base?.canBuild ? (
                                <Button
                                    className="self-start"
                                    disabled={base.treasury < farms.price}
                                    onClick={() => act(buyFarm.url(region.id))}
                                >
                                    <Wheat className="size-4" />
                                    {base.treasury < farms.price
                                        ? `Treasury needs ${farms.price - base.treasury} more Corn`
                                        : `Buy it for ${farms.price} Corn`}
                                </Button>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    {base
                                        ? "Your faction's leader and council can buy it."
                                        : 'Join or found a faction to buy farms.'}
                                </p>
                            ))}
                        {region.growth && (
                            <div className="mt-1 flex flex-col gap-2 border-t border-yellow-400/30 pt-2">
                                <p className="font-semibold">
                                    Grown {region.growth.grown} of{' '}
                                    {region.growth.max} times
                                </p>
                                {region.growth.next ? (
                                    <>
                                        <p className="text-muted-foreground">
                                            Next it grows into{' '}
                                            {region.growth.next.name}, a new
                                            region of this farm in its holder's
                                            colour.
                                        </p>
                                        {holds(region) && base?.canBuild ? (
                                            <>
                                                <CostList
                                                    cost={
                                                        region.growth.next.cost
                                                    }
                                                    store={base}
                                                />
                                                <Button
                                                    className="self-start"
                                                    disabled={
                                                        shortOf(
                                                            region.growth.next
                                                                .cost,
                                                            base,
                                                        ).length > 0
                                                    }
                                                    onClick={() =>
                                                        act(
                                                            growFarm.url(
                                                                region.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Sprout className="size-4" />
                                                    Grow into{' '}
                                                    {region.growth.next.name}
                                                </Button>
                                            </>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                {holds(region)
                                                    ? "Your faction's leader and council can grow it."
                                                    : 'Only the faction holding this farm can grow it.'}
                                            </p>
                                        )}
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        It has grown into all the land round it.
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
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
                        {region.gatedUntil ? (
                            <p className="text-muted-foreground">
                                It's shut behind an Iron Gate until{' '}
                                {clockTime(region.gatedUntil)}.
                            </p>
                        ) : region.settledUntil ? (
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
                                        disabled={horseshoesLeft < horseshoes}
                                        onClick={() =>
                                            act(attack.url(region.id), {
                                                horseshoes,
                                            })
                                        }
                                    >
                                        <Swords className="size-4" /> Raid
                                    </Button>
                                </div>
                                {horseshoesLeft < horseshoes && (
                                    <p className="text-xs text-orange-300">
                                        You have {horseshoesLeft}{' '}
                                        {horseshoesLeft === 1
                                            ? 'horseshoe'
                                            : 'horseshoes'}
                                        . Put in fewer, or wait for more.
                                    </p>
                                )}
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

                {plunderable && (
                    <div className="flex flex-col gap-2 rounded-lg border border-orange-500/40 p-3">
                        <p className="flex items-center gap-2 font-semibold">
                            <Flame
                                className="size-4 text-orange-300"
                                aria-hidden
                            />{' '}
                            Plunder {region.name}
                        </p>
                        <p className="text-muted-foreground">
                            Carry off {plunder.crop} {region.produces} and up to{' '}
                            {plunder.corn} Corn from {region.faction?.name}'s
                            treasury. It settles in half an hour: you may be
                            caught, and the House may put a warrant out for you.
                        </p>
                        {!isHere ? (
                            <p className="text-xs text-muted-foreground">
                                Walk onto it to plunder it.
                            </p>
                        ) : (player?.level ?? 1) < plunder.level ? (
                            <p className="text-xs text-muted-foreground">
                                Crime opens up at level {plunder.level}.
                            </p>
                        ) : (
                            <Button
                                variant="secondary"
                                className="self-start"
                                onClick={() =>
                                    act(crime.url(), { kind: 'plunder' })
                                }
                            >
                                <Flame className="size-4" /> Plunder (
                                {plunder.horseshoes} horseshoes)
                            </Button>
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
                            Ironfield's {gateNight.humans} men raid at 19:00. If
                            the gate holds, every defender earns 15 Rosettes; if
                            not, two outer fields burn.{' '}
                            <GateStrength gate={gateNight} />
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

/** What is left to gather here, and when it grows back. */
function StockLine({
    stock,
    produces,
}: {
    stock: NonNullable<MapRegion['stock']>;
    produces: string;
}) {
    const name = produces === 'corn' ? 'Corn' : produces;

    return (
        <div className="flex flex-col gap-1 text-xs text-muted-foreground">
            <span className="h-1.5 overflow-hidden rounded-full bg-muted">
                <span
                    className={
                        stock.left === 0
                            ? 'block h-full bg-orange-400'
                            : 'block h-full bg-emerald-400'
                    }
                    style={{
                        width: `${Math.round((stock.left / stock.max) * 100)}%`,
                    }}
                />
            </span>
            <span>
                {stock.left === 0 && stock.nextAt
                    ? `Picked clean. More ${name} grows back by ${clockTime(stock.nextAt)}.`
                    : `${stock.left} of ${stock.max} ${name} left here.`}{' '}
                {stock.fullAt
                    ? `${stock.perHour} grow back an hour; full by ${clockTime(stock.fullAt)}.`
                    : 'Full.'}
            </span>
        </div>
    );
}
