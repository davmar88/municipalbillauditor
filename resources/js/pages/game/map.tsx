import { Head, usePage } from '@inertiajs/react';
import { Flame, Shield, Swords } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { HorseshoePicker } from '@/components/game/horseshoe-picker';
import { MapHud } from '@/components/game/map-hud';
import { RegionPanel } from '@/components/game/region-panel';
import { WorldMap } from '@/components/game/world-map';
import { Button } from '@/components/ui/button';
import { act, clockTime, fromNow } from '@/lib/game';
import { commit, gate } from '@/routes/play';
import type {
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
    gate: { open: boolean; defended: boolean };
    yields: { corn: number; material: number };
    base: FactionStore | null;
    buildingKinds: BuildingKindCard[];
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
    yields,
    base,
    buildingKinds,
}: Props) {
    const { player } = usePage().props;
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [horseshoes, setHorseshoes] = useState(1);
    const selected = regions.find((region) => region.id === selectedId) ?? null;

    return (
        <>
            <Head title="Map" />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-4 p-3 md:p-5">
                <GameBar />

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
                                    Ironfield's men raid at 19:00. If the gate
                                    holds, every defender earns 15 Rosettes; if
                                    not, two outer fields burn.
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
                                {battle.joined ? (
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
                            regions={regions}
                            here={here}
                            house={house}
                            battles={battles}
                            yields={yields}
                            gateNight={gateNight}
                            base={base}
                            buildingKinds={buildingKinds}
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
