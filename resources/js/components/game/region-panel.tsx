import { Link, usePage } from '@inertiajs/react';
import {
    Crosshair,
    Crown,
    Flame,
    Gavel,
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
import { useState } from 'react';
import {
    BuildingDetails,
    BuildOptions,
    CostList,
    shortOf,
} from '@/components/game/faction-base';
import { useEscape } from '@/components/game/animal-menu';
import { Horseshoe } from '@/components/game/game-bar';
import { WhyNot, needHorseshoes, storeShort } from '@/components/game/why-not';
import { HorseshoePicker } from '@/components/game/horseshoe-picker';
import { ProfileLink } from '@/components/game/profile-link';
import { Button } from '@/components/ui/button';
import { act, capitalise, clockTime, fromNow, speciesEmoji } from '@/lib/game';
import { openChat } from '@/lib/pulse';
import { GateStrength } from '@/components/game/gate-strength';
import { DistrictFolk } from '@/components/game/irongate-folk';
import { arrest as arrestOutlaw } from '@/routes/play/warrant';
import {
    attack,
    buyFarm,
    growFarm,
    ironfield as ironfieldMission,
    waterFarm,
    digTrench,
    fillTrench,
    commit,
    crime,
    faction as factionPage,
    gate,
    gather,
    market,
    wall,
} from '@/routes/play';
import type {
    GateNightInfo,
    BuildingKindCard,
    FactionRef,
    FactionStore,
    IrongateInfo,
    MapBattle,
    MapRegion,
} from '@/types/game';

/** An open warrant, as the map knows it. */
export type Warrant = {
    id: number;
    playerId: number;
    name: string;
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
    city: 'Irongate',
    frontier: 'Frontier',
    ironfield: 'Ironfield',
};

type Props = {
    region: MapRegion;
    here: number | null;
    house: FactionRef | null;
    battles: MapBattle[];
    yields: { corn: number; material: number };
    gateNight: GateNightInfo;
    base: FactionStore | null;
    buildingKinds: BuildingKindCard[];
    farms: {
        price: number;
        income: number;
        water: number;
        waterHours: number;
        fill: number;
    };
    warrants: Warrant[];
    plunder: Plunder;
    /** Irongate's people, for the town's districts. */
    irongate: IrongateInfo;
    regions: MapRegion[];
    onClose: () => void;
};

/**
 * The card for the region you tapped: who holds it, who stands there, and
 * what you can do from where you are. The rules are the server's; the card
 * only explains them.
 */
export function RegionPanel({
    region,
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
    irongate,
    regions,
    onClose,
}: Props) {
    const { player } = usePage().props;
    // Escape closes the card, as it does the animal menu.
    useEscape(onClose);
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

    const myFaction = player?.faction ?? null;
    const holds = (other?: MapRegion) =>
        myFaction !== null && other?.faction?.id === myFaction.id;
    const isHere = region.id === here;
    const hereKey = regions.find((other) => other.id === here)?.key ?? null;
    const frontLine = here !== null && region.neighbours.includes(here);
    const raidable =
        myFaction !== null &&
        region.ring !== 'safe' &&
        region.ring !== 'city' &&
        !holds(region);
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
        holds(region) && region.ring !== 'safe' && region.building === null;

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
                        ) : region.ring === 'city' ? (
                            "The humans' town. Nobody holds it."
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
                {region.ring === 'city' && (
                    <DistrictFolk
                        region={region}
                        irongate={irongate}
                        warrants={warrants}
                        regions={regions}
                        here={here}
                    />
                )}
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
                {region.ironfield && (
                    <IronfieldMission
                        mission={region.ironfield}
                        regionId={region.id}
                        isHere={isHere}
                        horseshoesLeft={horseshoesLeft}
                    />
                )}
                {region.stock && region.richness < 1 && (
                    <p className="-mt-1 text-xs text-muted-foreground">
                        Far from the farms, the land is thinner: it holds and
                        gives {Math.round(region.richness * 100)}% of what land
                        by a farm does.
                    </p>
                )}

                {isHere && (
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
                                        ? `about ${Math.max(1, Math.floor(yields.corn * region.richness))} Corn`
                                        : `about ${Math.max(1, Math.floor(yields.material * region.richness))} ${region.produces}`}
                                    <span className="ml-auto flex items-center gap-1 opacity-80">
                                        <Horseshoe className="size-4" />1
                                    </span>
                                </Button>
                                <WhyNot
                                    reason={
                                        needHorseshoes(horseshoesLeft, 1) ||
                                        (region.stock?.left === 0 &&
                                            `${region.name} is picked clean. More grows back${region.stock.nextAt ? ` by ${clockTime(region.stock.nextAt)}` : ' soon'}; try another region meanwhile.`)
                                    }
                                />
                                {horseshoesLeft < 1 && outOfHorseshoes}
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                Nothing grows here. You gather in fields, woods
                                and yards.
                            </p>
                        )}
                    </div>
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
                        {region.wateredUntil !== null && region.irrigated && (
                            <div className="flex flex-col gap-2 rounded-lg border border-sky-400/30 bg-sky-400/10 p-3">
                                <p>
                                    💧{' '}
                                    {region.trench
                                        ? `A trench runs water here from ${region.trench.from}. It never runs dry while the trench holds.`
                                        : region.building?.kind === 'well' ||
                                            !region.partOf
                                          ? 'A Well keeps it watered. It never runs dry while the Well stands.'
                                          : 'The trench to its farm waters it too. It never runs dry while the trench holds.'}
                                </p>
                                {region.trench &&
                                    (holds(region) ? (
                                        <p className="text-xs text-muted-foreground">
                                            Rivals can fill it in for{' '}
                                            {farms.fill} horseshoes where it
                                            runs. Keep an eye on it.
                                        </p>
                                    ) : hereKey !== null &&
                                      region.trench.keys.includes(hereKey) ? (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                className="self-start"
                                                disabled={
                                                    horseshoesLeft < farms.fill
                                                }
                                                onClick={() =>
                                                    act(
                                                        fillTrench.url(
                                                            region.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Fill it in ({farms.fill}{' '}
                                                horseshoes)
                                            </Button>
                                            <WhyNot
                                                reason={needHorseshoes(
                                                    horseshoesLeft,
                                                    farms.fill,
                                                )}
                                            />
                                        </>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            Rivals can fill it in for{' '}
                                            {farms.fill} horseshoes, standing
                                            anywhere it runs.
                                        </p>
                                    ))}
                            </div>
                        )}
                        {region.wateredUntil !== null && !region.irrigated && (
                            <div className="flex flex-col gap-2 rounded-lg border border-sky-400/30 bg-sky-400/10 p-3">
                                <p>
                                    {region.wateredUntil
                                        ? `💧 Watered until ${clockTime(region.wateredUntil)} (${fromNow(region.wateredUntil)}).`
                                        : '💧 Parched. Nothing can be gathered here and it pays nothing until it is watered.'}{' '}
                                    {farms.water} water keeps it growing for{' '}
                                    {farms.waterHours} hours; anyone standing
                                    here can water it.
                                </p>
                                {isHere ? (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            className="self-start"
                                            disabled={
                                                Number(
                                                    (
                                                        (player?.materials ??
                                                            {}) as Record<
                                                            string,
                                                            number
                                                        >
                                                    ).water ?? 0,
                                                ) < farms.water
                                            }
                                            onClick={() =>
                                                act(waterFarm.url(region.id))
                                            }
                                        >
                                            Water it ({farms.water} water)
                                        </Button>
                                        <WhyNot
                                            reason={
                                                Number(
                                                    (
                                                        (player?.materials ??
                                                            {}) as Record<
                                                            string,
                                                            number
                                                        >
                                                    ).water ?? 0,
                                                ) < farms.water &&
                                                `You need ${farms.water} water. Gather it at a pond, mere or spring, then come back.`
                                            }
                                        />
                                    </>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Walk here with {farms.water} water to
                                        water it. Ponds, meres and springs give
                                        water.
                                    </p>
                                )}
                            </div>
                        )}
                        {region.trenchPlan && !region.irrigated && (
                            <div className="flex flex-col gap-2 rounded-lg border border-sky-400/30 p-3">
                                <p className="font-semibold">Dig a trench</p>
                                {region.trenchPlan.from &&
                                region.trenchPlan.cost ? (
                                    <>
                                        <p className="text-muted-foreground">
                                            Run water from{' '}
                                            {region.trenchPlan.from},{' '}
                                            {region.trenchPlan.stretches}{' '}
                                            {region.trenchPlan.stretches === 1
                                                ? 'region'
                                                : 'regions'}{' '}
                                            away, and it never runs dry again.
                                            The patches it grows into get water
                                            too.
                                        </p>
                                        {base?.canBuild ? (
                                            <>
                                                <CostList
                                                    cost={
                                                        region.trenchPlan.cost
                                                    }
                                                    store={base}
                                                />
                                                {isHere ? (
                                                    <>
                                                        <Button
                                                            className="self-start"
                                                            disabled={
                                                                shortOf(
                                                                    region
                                                                        .trenchPlan
                                                                        .cost,
                                                                    base,
                                                                ).length > 0
                                                            }
                                                            onClick={() =>
                                                                act(
                                                                    digTrench.url(
                                                                        region.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Dig the trench
                                                        </Button>
                                                        <WhyNot
                                                            reason={storeShort(
                                                                shortOf(
                                                                    region
                                                                        .trenchPlan
                                                                        .cost,
                                                                    base,
                                                                ),
                                                            )}
                                                        />
                                                    </>
                                                ) : (
                                                    <p className="text-xs text-muted-foreground">
                                                        Walk here to dig it.
                                                    </p>
                                                )}
                                            </>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                Your faction's leader and
                                                council can dig it.
                                            </p>
                                        )}
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        No pond, mere or spring is near enough
                                        to dig a trench from.
                                    </p>
                                )}
                            </div>
                        )}
                        <p className="text-muted-foreground">
                            It pays {farms.income} Corn into its holder's
                            treasury every half hour while watered.{' '}
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
                                                <WhyNot
                                                    reason={storeShort(
                                                        shortOf(
                                                            region.growth.next
                                                                .cost,
                                                            base,
                                                        ),
                                                    )}
                                                />
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
                                    <WhyNot
                                        reason={needHorseshoes(
                                            horseshoesLeft,
                                            horseshoes,
                                        )}
                                    />
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

                {!myFaction &&
                    region.ring !== 'safe' &&
                    region.ring !== 'city' && (
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

                {region.key === 'farmhouse' &&
                    region.faction &&
                    region.faction.id !== house?.id && (
                        <p className="rounded-lg border border-yellow-400/40 bg-yellow-400/10 p-3">
                            {region.faction.name} holds the Farmhouse but
                            doesn't rule yet: it rules once its Head Office
                            stands. Until then the Farmhouse can be taken.
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
                            canHelp={
                                holds(region) &&
                                isHere &&
                                !region.building.helped &&
                                (region.building.builders ?? 0) < 6
                            }
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
                            plot={{
                                key: region.key,
                                produces: region.produces,
                                farm: region.ring === 'farm',
                            }}
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

/** Ironfield's mission in this region: slip past the men and carry off what they dig, or end up in the pound. */
function IronfieldMission({
    mission,
    regionId,
    isHere,
    horseshoesLeft,
}: {
    mission: NonNullable<MapRegion['ironfield']>;
    regionId: number;
    isHere: boolean;
    horseshoesLeft: number;
}) {
    const [shoes, setShoes] = useState(Math.min(3, mission.odds.length));
    const chance = mission.odds[shoes - 1] ?? 0;
    const loot = Object.entries(mission.reward.materials)
        .map(([material, amount]) => `${amount} ${material}`)
        .join(', ');

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-orange-400/40 bg-orange-400/10 p-3">
            <p className="flex items-center gap-2 font-semibold">
                <Swords className="size-4 text-orange-300" aria-hidden />
                {mission.title}
                <span className="ml-auto text-xs font-normal text-orange-200">
                    Depth {mission.depth} of 4
                </span>
            </p>
            <p className="text-muted-foreground">
                {mission.men} men stand guard: {mission.guards}. Get away with
                it for {mission.reward.corn} Corn, {loot} and{' '}
                {mission.reward.rosettes} Rosettes. Get caught and they drag you
                to the pound for {mission.poundHours}{' '}
                {mission.poundHours === 1 ? 'hour' : 'hours'}.
            </p>
            {mission.nextAt ? (
                <p className="text-xs text-muted-foreground">
                    The men here are still on their guard. Try again{' '}
                    {fromNow(mission.nextAt)}.
                </p>
            ) : isHere ? (
                <>
                    <label className="flex items-center gap-2 text-xs">
                        <span className="shrink-0">Horseshoes: {shoes}</span>
                        <input
                            type="range"
                            min={1}
                            max={mission.odds.length}
                            value={shoes}
                            onChange={(event) =>
                                setShoes(Number(event.target.value))
                            }
                            className="w-full accent-orange-400"
                        />
                    </label>
                    <p className="text-xs">
                        {Math.round(chance * 100)}% chance to get away with it.
                    </p>
                    <Button
                        size="sm"
                        className="self-start"
                        disabled={horseshoesLeft < shoes}
                        onClick={() =>
                            act(ironfieldMission.url(regionId), {
                                horseshoes: shoes,
                            })
                        }
                    >
                        Go in with {shoes}{' '}
                        {shoes === 1 ? 'horseshoe' : 'horseshoes'}
                    </Button>
                    <WhyNot reason={needHorseshoes(horseshoesLeft, shoes)} />
                </>
            ) : (
                <p className="text-xs text-muted-foreground">
                    Walk here to take it on. The deeper into Ironfield, the more
                    men and the bigger the haul.
                </p>
            )}
        </div>
    );
}
