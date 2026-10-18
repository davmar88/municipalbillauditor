import { Head, router, usePage } from '@inertiajs/react';
import { Flame, Footprints, Shield, Swords } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { GateStrength } from '@/components/game/gate-strength';
import { HorseshoePicker } from '@/components/game/horseshoe-picker';
import { MapHud } from '@/components/game/map-hud';
import { RegionPanel } from '@/components/game/region-panel';
import type { Plunder, Warrant } from '@/components/game/region-panel';
import { WorldMap } from '@/components/game/world-map';
import { Button } from '@/components/ui/button';
import { act, clockTime, fromNow } from '@/lib/game';
import { commit, gate, walkStop, walkTo as walkToRoute } from '@/routes/play';
import type {
    GateNightInfo,
    MapMe,
    BuildingKindCard,
    FactionRef,
    FactionStore,
    MapBattle,
    MapLaw,
    MapRegion,
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
    me: MapMe;
    yields: { corn: number; material: number };
    base: FactionStore | null;
    buildingKinds: BuildingKindCard[];
    farms: { price: number; income: number };
    warrants: Warrant[];
    plunder: Plunder;
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
    me,
    yields,
    base,
    buildingKinds,
    farms,
    warrants,
    plunder,
}: Props) {
    const { player } = usePage().props;
    const [selectedId, setSelectedId] = useState<number | null>(null);
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

                {(gateNight.open || battles.length > 0) && (
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

                <WorldMap
                    valley={valley}
                    regions={regions}
                    here={here}
                    house={house}
                    species={player?.species ?? null}
                    selectedId={selectedId}
                    onSelect={setSelectedId}
                    gateNight={gateNight.open}
                    me={me}
                    onWalk={walkTo}
                    gateResult={
                        gateNight.result === null
                            ? null
                            : gateNight.result.held
                              ? 'held'
                              : 'fell'
                    }
                    wanted={wanted}
                >
                    <MapHud
                        valley={valley}
                        season={season}
                        house={house}
                        laws={laws}
                        regions={regions}
                        compact={selected !== null}
                    />
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
                            onClose={() => setSelectedId(null)}
                        />
                    )}
                </WorldMap>

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
