import { Head, router, usePage } from '@inertiajs/react';
import { Flame, Footprints, Shield, Swords } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { GameMap } from '@/components/game/game-map';
import { HomeCard } from '@/components/game/home-card';
import { GateStrength } from '@/components/game/gate-strength';
import { HorseshoePicker } from '@/components/game/horseshoe-picker';
import { MapHud } from '@/components/game/map-hud';
import { RegionPanel } from '@/components/game/region-panel';
import type { Plunder, Warrant } from '@/components/game/region-panel';
import { Button } from '@/components/ui/button';
import { WhyNot, needHorseshoes } from '@/components/game/why-not';
import { act, clockTime, fromNow } from '@/lib/game';
import {
    commit,
    gate,
    gather,
    walkStop,
    walkTo as walkToRoute,
} from '@/routes/play';
import { camp as campRoute } from '@/routes/play/home';
import { fight as fightPlunderers } from '@/routes/play/plunderers';
import type {
    GateNightInfo,
    MapMe,
    BuildingKindCard,
    FactionRef,
    FactionStore,
    HomeInfo,
    IrongateInfo,
    MapHome,
    MapBattle,
    MapLaw,
    MapRegion,
    PlunderBandInfo,
    Worn,
} from '@/types/game';

type Props = {
    valley: string;
    season: { number: number; endsAt: string; unrest: number } | null;
    house: FactionRef | null;
    regions: MapRegion[];
    here: number | null;
    laws: MapLaw[];
    battles: MapBattle[];
    recent: { id: number; text: string; at: string }[];
    gate: GateNightInfo;
    plunderers: PlunderBandInfo | null;
    me: MapMe;
    /** What your animal wears, for the 3D map. */
    wearing: Worn[];
    yields: { corn: number; material: number };
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
    irongate: IrongateInfo;
    homes: MapHome[];
    home: HomeInfo;
    raidRules: {
        rules: string[];
        spare: number[];
        labels: Record<string, string>;
    } | null;
    /** Out in the open scrub: what there is to gather where you stand. */
    scrub: {
        id: number;
        produces: string;
        stock: { left: number; max: number } | null;
        richness: number;
    } | null;
};

export default function MapPage({
    valley,
    season,
    house,
    regions,
    here,
    laws,
    battles,
    recent,
    gate: gateNight,
    plunderers,
    me,
    wearing,
    yields,
    base,
    buildingKinds,
    farms,
    warrants,
    plunder,
    irongate,
    homes,
    home,
    scrub,
    raidRules,
}: Props) {
    const { player } = usePage().props;
    const [selectedId, setSelectedId] = useState<number | null>(null);
    // The open scrub right-clicked, to walk or set up camp there.
    const [ground, setGround] = useState<[number, number] | null>(null);
    // Your own home, tapped on the map, to manage it there.
    const [homeOpen, setHomeOpen] = useState(false);
    const [horseshoes, setHorseshoes] = useState(1);
    const selected = regions.find((region) => region.id === selectedId) ?? null;

    const walkTo = useCallback(([x, y]: [number, number]) => {
        router.post(
            walkToRoute.url(),
            { x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 },
            { preserveScroll: true, preserveState: true },
        );
    }, []);

    // Once you arrive, fetch where you are and what is round you.
    useEffect(() => {
        if (!me.walk) {
            return;
        }

        const timer = window.setTimeout(
            () =>
                router.reload({
                    only: ['me', 'here', 'regions', 'warrants', 'plunder'],
                }),
            Math.max(
                0,
                new Date(me.walk.arrivesAt).getTime() + 500 - Date.now(),
            ),
        );

        return () => window.clearTimeout(timer);
    }, [me.walk]);

    // When the next raid lands, fetch how it went, without a reload.
    const nextLanding = useMemo(
        () =>
            regions
                .flatMap((region) =>
                    region.incoming.map((raid) => raid.landsAt),
                )
                .sort()[0] ?? null,
        [regions],
    );

    useEffect(() => {
        if (!nextLanding) {
            return;
        }

        const timer = window.setTimeout(
            () =>
                router.reload({
                    only: ['regions', 'battles', 'recent', 'me', 'here'],
                }),
            Math.max(0, new Date(nextLanding).getTime() + 75_000 - Date.now()),
        );

        return () => window.clearTimeout(timer);
    }, [nextLanding]);

    // Once Ironfield has raided, fetch how it went, without a reload.
    useEffect(() => {
        if (!gateNight.open) {
            return;
        }

        const timer = window.setTimeout(
            () => router.reload({ only: ['gate', 'regions', 'season'] }),
            Math.max(0, new Date(gateNight.at).getTime() + 60_000 - Date.now()),
        );

        return () => window.clearTimeout(timer);
    }, [gateNight.open, gateNight.at]);
    const wanted = useMemo(
        () => warrants.map((warrant) => warrant.playerId),
        [warrants],
    );

    return (
        <>
            <Head title="Map" />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-4 p-3 md:p-5">
                <GameBar />

                {me.walk && (
                    <p className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-primary/40 bg-primary/10 px-4 py-2.5 text-sm">
                        <Footprints
                            className="size-4 shrink-0 text-primary"
                            aria-hidden
                        />
                        <span className="min-w-0 flex-1">
                            Walking
                            {me.walk.region
                                ? ` to ${me.walk.region}`
                                : ''} at{' '}
                            {me.pace} paces a minute. You arrive at{' '}
                            {clockTime(me.walk.arrivesAt)}.
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => act(walkStop.url())}
                        >
                            Stop here
                        </Button>
                    </p>
                )}

                {gateNight.result && <GateResult result={gateNight.result} />}

                {(gateNight.open || battles.length > 0 || plunderers) && (
                    <section
                        aria-label="Needs you now"
                        className="flex flex-col gap-2"
                    >
                        {gateNight.open && (
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-orange-500/40 bg-orange-500/10 px-4 py-2.5 text-sm">
                                <Flame
                                    className="size-4 shrink-0 text-orange-300"
                                    aria-hidden
                                />
                                <p className="min-w-0 flex-1">
                                    <span className="font-bold">
                                        Gate Night is tonight.
                                    </span>{' '}
                                    Ironfield's {gateNight.humans} men raid at
                                    19:00. If the gate holds, every defender
                                    earns 15 Rosettes; if not, two outer fields
                                    burn. <GateStrength gate={gateNight} />
                                </p>
                                {gateNight.defended ? (
                                    <span className="font-semibold text-primary">
                                        You're at the gate.
                                    </span>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <HorseshoePicker
                                            value={horseshoes}
                                            onChange={setHorseshoes}
                                        />
                                        <Button
                                            size="sm"
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
                        {plunderers && (
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-orange-500/40 bg-orange-500/10 px-4 py-2.5 text-sm">
                                <Swords
                                    className="size-4 shrink-0 text-orange-300"
                                    aria-hidden
                                />
                                <p className="min-w-0 flex-1">
                                    <span className="font-bold">
                                        {plunderers.men} plunderers from
                                        Irongate are riding for{' '}
                                        {plunderers.regionName}.
                                    </span>{' '}
                                    They strike at{' '}
                                    {clockTime(plunderers.landsAt)} (
                                    {fromNow(plunderers.landsAt)}). Every
                                    faction can fight them from here:{' '}
                                    {plunderers.fighters}{' '}
                                    {plunderers.fighters === 1
                                        ? 'animal is'
                                        : 'animals are'}{' '}
                                    in, about {plunderers.strength} against{' '}
                                    {plunderers.men} men. Win and the fighters
                                    share {plunderers.corn} Corn; lose and they
                                    sack {plunderers.holders ?? 'the farm'} and
                                    lay the fighters up for an hour.
                                </p>
                                {plunderers.fighting ? (
                                    <span className="font-semibold text-primary">
                                        You're riding out against them.
                                    </span>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <HorseshoePicker
                                            value={horseshoes}
                                            onChange={setHorseshoes}
                                        />
                                        <Button
                                            size="sm"
                                            disabled={
                                                !plunderers.canFight ||
                                                (player?.horseshoes ?? 0) <
                                                    horseshoes
                                            }
                                            onClick={() =>
                                                act(
                                                    fightPlunderers.url(
                                                        plunderers.id,
                                                    ),
                                                    { horseshoes },
                                                )
                                            }
                                        >
                                            Fight them
                                        </Button>
                                        <WhyNot
                                            reason={
                                                !plunderers.canFight
                                                    ? 'Factions fight the plunderers: join or found one first.'
                                                    : needHorseshoes(
                                                          player?.horseshoes ??
                                                              0,
                                                          horseshoes,
                                                      )
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        )}
                        {battles.map((battle) => (
                            <div
                                key={battle.id}
                                className={
                                    battle.side === 'attack'
                                        ? 'flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-primary/40 bg-primary/10 px-4 py-2.5 text-sm'
                                        : 'flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-orange-500/40 bg-orange-500/10 px-4 py-2.5 text-sm'
                                }
                            >
                                {battle.side === 'attack' ? (
                                    <Swords
                                        className="size-4 shrink-0 text-primary"
                                        aria-hidden
                                    />
                                ) : (
                                    <Shield
                                        className="size-4 shrink-0 text-orange-300"
                                        aria-hidden
                                    />
                                )}
                                <p className="min-w-0 flex-1">
                                    <span className="font-bold">
                                        {battle.side === 'attack'
                                            ? `Your faction is attacking ${battle.region}.`
                                            : `${battle.attacker} is attacking ${battle.region}.`}
                                    </span>{' '}
                                    Lands at {clockTime(battle.landsAt)} (
                                    {fromNow(battle.landsAt)}); your side has{' '}
                                    {battle.ourHorseshoes} horseshoes in.
                                </p>
                                {fromNow(battle.landsAt) === 'now' ? (
                                    <span className="font-semibold text-primary">
                                        Landing now: the result comes in a
                                        minute.
                                    </span>
                                ) : battle.joined ? (
                                    <span className="font-semibold text-primary">
                                        You're in this battle.
                                    </span>
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
                                )}
                            </div>
                        ))}
                    </section>
                )}

                <GameMap
                    valley={valley}
                    regions={regions}
                    here={here}
                    house={house}
                    species={player?.species ?? null}
                    selectedId={selectedId}
                    onSelect={(id) => {
                        setSelectedId(id);
                        setGround(null);
                    }}
                    gateNight={gateNight.open}
                    me={me}
                    onWalk={walkTo}
                    onGround={setGround}
                    onHome={() => {
                        setSelectedId(null);
                        setGround(null);
                        setHomeOpen(true);
                    }}
                    gateResult={
                        gateNight.result === null
                            ? null
                            : gateNight.result.held
                              ? 'held'
                              : 'fell'
                    }
                    wanted={wanted}
                    wearing={wearing}
                    irongate={irongate}
                    warrants={warrants}
                    homes={homes}
                >
                    <MapHud
                        valley={valley}
                        season={season}
                        house={house}
                        laws={laws}
                        regions={regions}
                        compact={selected !== null}
                    />
                    {homeOpen && !selected && (
                        <div className="absolute bottom-3 left-3 z-20 flex max-h-[calc(100%-1.5rem)] w-80 max-w-[calc(100%-1.5rem)] flex-col overflow-y-auto rounded-2xl shadow-lg">
                            <button
                                type="button"
                                className="absolute top-3 right-3 z-10 text-muted-foreground hover:text-foreground"
                                aria-label="Close"
                                onClick={() => setHomeOpen(false)}
                            >
                                ✕
                            </button>
                            <HomeCard
                                info={home}
                                homes={homes}
                                me={me}
                                onWalk={walkTo}
                            />
                        </div>
                    )}
                    {ground && !selected && (
                        <GroundCard
                            point={ground}
                            hasHome={homes.some((home) => home.mine)}
                            onWalk={walkTo}
                            onClose={() => setGround(null)}
                        />
                    )}
                    {selected && (
                        <RegionPanel
                            key={selected.id}
                            region={selected}
                            here={here}
                            house={house}
                            battles={battles}
                            yields={yields}
                            gateNight={gateNight}
                            base={base}
                            buildingKinds={buildingKinds}
                            farms={farms}
                            warrants={warrants}
                            plunder={plunder}
                            irongate={irongate}
                            regions={regions}
                            raidRules={raidRules}
                            onClose={() => setSelectedId(null)}
                        />
                    )}
                </GameMap>
                {scrub && <ScrubCard scrub={scrub} />}
                <HomeCard info={home} homes={homes} me={me} onWalk={walkTo} />
                <p className="-mt-2 text-xs text-muted-foreground">
                    Click or tap a spot to walk there. Right-click, or press and
                    hold on a phone, to see what's there.
                </p>

                {recent.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-lg font-bold">Lately</h2>
                        <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                            {recent.map((item) => (
                                <li key={item.id}>
                                    {clockTime(item.at)} · {item.text}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

/** How tonight's raid went, for the rest of the evening. */
function GateResult({
    result,
}: {
    result: NonNullable<GateNightInfo['result']>;
}) {
    const defenders =
        result.defenders === 1 ? '1 defender' : `${result.defenders} defenders`;

    return result.held ? (
        <p className="flex items-center gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-2.5 text-sm">
            <Shield className="size-4 shrink-0 text-emerald-300" aria-hidden />
            <span>
                <span className="font-bold">The gate held tonight.</span>{' '}
                Ironfield's men turned back at 19:00, and its {defenders} earned
                15 Rosettes each.
            </span>
        </p>
    ) : (
        <p className="flex items-center gap-2 rounded-xl border border-orange-500/40 bg-orange-500/10 px-4 py-2.5 text-sm">
            <Flame className="size-4 shrink-0 text-orange-300" aria-hidden />
            <span>
                <span className="font-bold">
                    Ironfield broke through the gate at 19:00.
                </span>{' '}
                {result.burned.length > 0
                    ? `They burned ${result.burned.join(' and ')}.`
                    : 'No field beyond the fences was held, so nothing burned.'}{' '}
                {result.defenders > 0
                    ? `Its ${defenders} earned 5 Rosettes each for fighting.`
                    : 'Nobody defended it.'}
            </span>
        </p>
    );
}

/** Out in the scrub between the regions: anyone gathers and camps here, but the further out, the less there is. */
function ScrubCard({ scrub }: { scrub: NonNullable<Props['scrub']> }) {
    const { player } = usePage().props;
    // The scrub never runs out; a stock, if any, would show what's left.
    const left = scrub.stock ? scrub.stock.left : Infinity;

    return (
        <section className="flex flex-col gap-2 rounded-lg border border-lime-700/40 bg-lime-950/30 p-3 text-sm">
            <p className="font-semibold">🌿 You're out in the scrub</p>
            <p className="text-muted-foreground">
                Open ground nobody holds. Anyone can gather and set up camp
                here. The further you go from the farms, the less there is: here
                you bring in {Math.round(scrub.richness * 100)}% of what you
                would by a farm.
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    disabled={left < 1}
                    onClick={() => act(gather.url(scrub.id))}
                >
                    Gather {scrub.produces}
                </Button>
                <WhyNot reason={needHorseshoes(player?.horseshoes ?? 0, 1)} />
                {scrub.stock && (
                    <span className="text-xs text-muted-foreground">
                        {scrub.stock.left} of {scrub.stock.max} left in the
                        scrub.
                    </span>
                )}
            </div>
        </section>
    );
}

/** The open scrub you right-clicked: walk there, or set up your own camp there. */
function GroundCard({
    point,
    hasHome,
    onWalk,
    onClose,
}: {
    point: [number, number];
    hasHome: boolean;
    onWalk: (point: [number, number]) => void;
    onClose: () => void;
}) {
    return (
        <div className="absolute bottom-3 left-3 z-20 flex w-72 max-w-[calc(100%-1.5rem)] flex-col gap-2 rounded-xl border bg-card/95 p-3 text-sm shadow-lg backdrop-blur">
            <div className="flex items-center justify-between">
                <p className="font-semibold">🌿 Open scrub</p>
                <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground"
                    aria-label="Close"
                    onClick={onClose}
                >
                    ✕
                </button>
            </div>
            <p className="text-xs text-muted-foreground">
                Nobody holds it. Anyone can walk here, gather and set up camp: a
                Tent is quick and cheap, a Hut keeps burners out. From afar, you
                walk there and it goes up when you arrive.
            </p>
            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                        onWalk(point);
                        onClose();
                    }}
                >
                    <Footprints className="size-4" />
                    Walk here
                </Button>
                {(
                    [
                        [0, '⛺ Pitch a Tent'],
                        [1, '🛖 Build a Hut'],
                    ] as const
                ).map(([level, label]) => (
                    <Button
                        key={level}
                        size="sm"
                        disabled={hasHome}
                        onClick={() => {
                            act(campRoute.url(), {
                                x: point[0],
                                y: point[1],
                                level,
                            });
                            onClose();
                        }}
                    >
                        {label} here
                    </Button>
                ))}
            </div>
            {hasHome && (
                <p className="text-xs text-muted-foreground">
                    You already have a home. Raise it to the next level instead.
                </p>
            )}
        </div>
    );
}
