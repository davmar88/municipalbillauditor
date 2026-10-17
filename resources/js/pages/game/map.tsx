import { Head, usePage } from '@inertiajs/react';
import { Crown, Shield, Swords } from 'lucide-react';
import { useState } from 'react';
import { GameBar, Horseshoe } from '@/components/game/game-bar';
import { UnrestMeter } from '@/components/game/unrest-meter';
import { Button } from '@/components/ui/button';
import { act, capitalise, clockTime, fromNow } from '@/lib/game';
import { cn } from '@/lib/utils';
import { attack, commit, gate, gather } from '@/routes/play';

type FactionRef = { id: number; name: string; colour: string };

type Region = {
    id: number;
    key: string;
    name: string;
    ring: string;
    produces: string | null;
    col: number;
    row: number;
    faction: FactionRef | null;
    settledUntil: string | null;
    neighbours: number[];
    incoming: {
        id: number;
        faction: { name: string; colour: string };
        landsAt: string;
    }[];
};

type Battle = {
    id: number;
    region: string;
    attacker: string;
    side: 'attack' | 'defence';
    landsAt: string;
    ourHorseshoes: number;
    joined: boolean;
};

type Props = {
    valley: string;
    season: { number: number; endsAt: string; unrest: number } | null;
    house: FactionRef | null;
    regions: Region[];
    battles: Battle[];
    recent: { id: number; text: string; at: string }[];
    gate: { open: boolean; defended: boolean };
    yields: { corn: number; material: number };
};

const CELL = 128;
const GAP = 16;
const LEFT = 28;
const TOP = 16;

export default function MapPage({
    valley,
    season,
    house,
    regions,
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
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
                <GameBar />

                <section className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight">
                            {valley}
                        </h1>
                        {season && (
                            <p className="text-sm text-muted-foreground">
                                Season {season.number} ends{' '}
                                {fromNow(season.endsAt)}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <span className="flex items-center gap-1.5">
                            <Crown
                                className="size-4 text-primary"
                                aria-hidden
                            />
                            {house ? (
                                <>
                                    <span
                                        className="font-semibold"
                                        style={{ color: house.colour }}
                                    >
                                        {house.name}
                                    </span>{' '}
                                    rules
                                </>
                            ) : (
                                'Nobody holds the Farmhouse'
                            )}
                        </span>
                        {season && <UnrestMeter unrest={season.unrest} />}
                    </div>
                </section>

                <div className="overflow-x-auto rounded-xl border bg-card p-2">
                    <svg
                        viewBox="0 0 760 330"
                        className="w-full min-w-[560px]"
                        role="img"
                        aria-label={`Map of ${valley}`}
                    >
                        <defs>
                            <linearGradient
                                id="house"
                                x1="0"
                                y1="0"
                                x2="1"
                                y2="1"
                            >
                                <stop offset="0" stopColor="#41d1ff" />
                                <stop offset="0.5" stopColor="#b39aff" />
                                <stop offset="1" stopColor="#d18cff" />
                            </linearGradient>
                        </defs>
                        {regions.map((region) => (
                            <RegionTile
                                key={region.id}
                                region={region}
                                isHouse={
                                    house !== null &&
                                    region.faction?.id === house.id
                                }
                                mine={
                                    player?.faction !== null &&
                                    region.faction?.id === player?.faction?.id
                                }
                                selected={region.id === selectedId}
                                onSelect={() => setSelectedId(region.id)}
                            />
                        ))}
                    </svg>
                </div>

                {selected && (
                    <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-xl font-bold">
                                {selected.name}
                            </h2>
                            <span className="text-sm text-muted-foreground">
                                {selected.faction ? (
                                    <>
                                        Held by{' '}
                                        <span
                                            className="font-semibold"
                                            style={{
                                                color: selected.faction.colour,
                                            }}
                                        >
                                            {selected.faction.name}
                                        </span>
                                    </>
                                ) : selected.ring === 'safe' ? (
                                    'Safe ground for newcomers'
                                ) : (
                                    'Nobody holds it'
                                )}
                            </span>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {selected.produces && (
                                <Button
                                    variant="secondary"
                                    onClick={() => act(gather.url(selected.id))}
                                >
                                    <Horseshoe className="size-4" /> Gather{' '}
                                    {selected.produces === 'corn'
                                        ? `${yields.corn} Corn`
                                        : `${yields.material} ${selected.produces}`}
                                </Button>
                            )}

                            {player?.faction &&
                                selected.ring !== 'safe' &&
                                selected.faction?.id !== player.faction.id && (
                                    <div className="flex items-center gap-2">
                                        <HorseshoePicker
                                            value={horseshoes}
                                            onChange={setHorseshoes}
                                        />
                                        <Button
                                            onClick={() =>
                                                act(attack.url(selected.id), {
                                                    horseshoes,
                                                })
                                            }
                                        >
                                            <Swords className="size-4" /> Raid
                                        </Button>
                                    </div>
                                )}
                        </div>

                        {selected.settledUntil && (
                            <p className="text-sm text-muted-foreground">
                                Just changed hands. It can be attacked again at{' '}
                                {clockTime(selected.settledUntil)}.
                            </p>
                        )}

                        {selected.incoming.length > 0 && (
                            <ul className="flex flex-col gap-1 text-sm">
                                {selected.incoming.map((incoming) => (
                                    <li key={incoming.id}>
                                        <span
                                            className="font-semibold"
                                            style={{
                                                color: incoming.faction.colour,
                                            }}
                                        >
                                            {incoming.faction.name}
                                        </span>{' '}
                                        is marching here and lands at{' '}
                                        {clockTime(incoming.landsAt)}.
                                    </li>
                                ))}
                            </ul>
                        )}

                        {!player?.faction && selected.ring !== 'safe' && (
                            <p className="text-sm text-muted-foreground">
                                Join a faction to raid. Lone wolves loot and
                                burn instead, from the Faction tab.
                            </p>
                        )}
                    </section>
                )}

                {!selected && (
                    <p className="text-sm text-muted-foreground">
                        Tap a region to gather there or raid it. You can only
                        raid next to land your faction holds; factions without
                        land start from the regions beside the Paddock.
                    </p>
                )}

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

function RegionTile({
    region,
    isHouse,
    mine,
    selected,
    onSelect,
}: {
    region: Region;
    isHouse: boolean;
    mine: boolean;
    selected: boolean;
    onSelect: () => void;
}) {
    const safe = region.ring === 'safe';
    const x = safe ? LEFT : LEFT + region.col * (CELL + GAP);
    const y = TOP + region.row * (64 + GAP);
    const width = safe ? 5 * CELL + 4 * GAP : CELL;
    const height = safe ? 52 : 64;
    const colour = region.faction?.colour;
    const contested = region.incoming.length > 0;

    return (
        <g
            role="button"
            tabIndex={0}
            aria-label={`${region.name}${region.faction ? `, held by ${region.faction.name}` : ''}`}
            onClick={onSelect}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onSelect();
                }
            }}
            className="cursor-pointer outline-none"
        >
            <rect
                x={x}
                y={y}
                width={width}
                height={height}
                rx="10"
                fill={colour ?? (safe ? '#7ee787' : '#16171d')}
                fillOpacity={colour ? 0.22 : safe ? 0.08 : 1}
                stroke={
                    contested
                        ? '#dd6b30'
                        : isHouse
                          ? 'url(#house)'
                          : (colour ?? (safe ? '#7ee787' : '#2e2e32'))
                }
                strokeWidth={
                    selected ? 4 : isHouse || contested ? 3 : mine ? 2.5 : 1.25
                }
                className={cn(contested && 'animate-pulse')}
            />
            <text
                x={x + width / 2}
                y={y + (safe ? 22 : 28)}
                textAnchor="middle"
                fontSize="14"
                fontWeight="700"
                fill="#ffffff"
            >
                {region.name}
            </text>
            <text
                x={x + width / 2}
                y={y + (safe ? 40 : 47)}
                textAnchor="middle"
                fontSize="11.5"
                fill="#b9b9bb"
            >
                {safe
                    ? 'Home for newcomers, never attacked'
                    : region.key === 'farmhouse'
                      ? 'Seat of power'
                      : region.faction
                        ? region.faction.name
                        : region.produces
                          ? capitalise(region.produces)
                          : ''}
            </text>
        </g>
    );
}

function HorseshoePicker({
    value,
    onChange,
}: {
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <div
            className="flex overflow-hidden rounded-md border"
            role="group"
            aria-label="Horseshoes to put in"
        >
            {[1, 2, 3].map((count) => (
                <button
                    key={count}
                    type="button"
                    onClick={() => onChange(count)}
                    aria-pressed={value === count}
                    className={cn(
                        'flex h-9 w-9 items-center justify-center text-sm font-semibold',
                        value === count
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-transparent',
                    )}
                >
                    {count}
                </button>
            ))}
        </div>
    );
}
