import { Head, usePage } from '@inertiajs/react';
import { Shield, Swords } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { HorseshoePicker } from '@/components/game/horseshoe-picker';
import { MapHud } from '@/components/game/map-hud';
import { RegionPanel } from '@/components/game/region-panel';
import { WorldMap } from '@/components/game/world-map';
import { Button } from '@/components/ui/button';
import { act, clockTime, fromNow } from '@/lib/game';
import { commit, gate } from '@/routes/play';
import type { FactionRef, MapBattle, MapLaw, MapRegion } from '@/types/game';

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
                            onClose={() => setSelectedId(null)}
                        />
                    )}
                </WorldMap>

                {battles.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-lg font-bold">
                            Your faction's battles
                        </h2>
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {battles.map((battle) => (
                                <li
                                    key={battle.id}
                                    className="flex flex-col gap-2 rounded-xl border bg-card p-4"
                                >
                                    <p className="flex items-center gap-2 font-semibold">
                                        {battle.side === 'attack' ? (
                                            <Swords className="size-4 text-primary" />
                                        ) : (
                                            <Shield className="size-4 text-cyan-300" />
                                        )}
                                        {battle.side === 'attack'
                                            ? `Attacking ${battle.region}`
                                            : `${battle.attacker} attacks ${battle.region}`}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Lands at {clockTime(battle.landsAt)} (
                                        {fromNow(battle.landsAt)}). Your side
                                        has {battle.ourHorseshoes} horseshoes
                                        in.
                                    </p>
                                    {battle.joined ? (
                                        <p className="text-sm text-primary">
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
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {gateNight.open && (
                    <section className="flex flex-col gap-3 rounded-xl border border-orange-500/40 bg-orange-500/10 p-4">
                        <h2 className="text-lg font-bold">
                            Gate Night is tonight
                        </h2>
                        <p className="text-sm">
                            Ironfield's men raid at 19:00. Any animal can help
                            hold the gate. If it holds, every defender earns 15
                            Rosettes; if not, two outer fields burn.
                        </p>
                        {gateNight.defended ? (
                            <p className="text-sm text-primary">
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
                    </section>
                )}

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
