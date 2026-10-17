import { Link } from '@inertiajs/react';
import { ChevronDown, Crown, Flag, ScrollText } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { UnrestMeter } from '@/components/game/unrest-meter';
import { fromNow } from '@/lib/game';
import { cn } from '@/lib/utils';
import { wall } from '@/routes/play';
import type { FactionRef, MapLaw, MapRegion } from '@/types/game';

type Props = {
    valley: string;
    season: { number: number; endsAt: string; unrest: number } | null;
    house: FactionRef | null;
    laws: MapLaw[];
    regions: MapRegion[];
    compact: boolean;
};

/** The corner of the map that says who rules, what the laws are and who holds what. */
export function MapHud({
    valley,
    season,
    house,
    laws,
    regions,
    compact,
}: Props) {
    const [open, setOpen] = useState<'laws' | 'factions' | null>(null);
    const [expanded, setExpanded] = useState(false);
    const standings = useMemo(() => {
        const held = new Map<number, { faction: FactionRef; count: number }>();

        regions.forEach((region) => {
            if (region.faction) {
                const entry = held.get(region.faction.id) ?? {
                    faction: region.faction,
                    count: 0,
                };
                entry.count++;
                held.set(region.faction.id, entry);
            }
        });

        return [...held.values()].sort((a, b) => b.count - a.count);
    }, [regions]);
    const unclaimed = regions.filter(
        (region) => !region.faction && region.ring !== 'safe',
    ).length;
    const toggle = (panel: 'laws' | 'factions') =>
        setOpen(open === panel ? null : panel);

    return (
        <div className="pointer-events-none absolute top-3 left-3 flex max-w-[calc(100%-4.5rem)] flex-col items-start gap-2 md:max-w-xs">
            <div className="pointer-events-auto rounded-xl border bg-card/90 px-3 py-2 shadow-lg backdrop-blur">
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-baseline gap-x-2">
                            <h1 className="text-base font-extrabold tracking-tight md:text-lg">
                                {valley}
                            </h1>
                            {season && (
                                <span
                                    className={cn(
                                        'text-xs text-muted-foreground',
                                        !expanded && 'max-md:hidden',
                                    )}
                                >
                                    Season {season.number} ends{' '}
                                    {fromNow(season.endsAt)}
                                </span>
                            )}
                        </div>
                        <p className="mt-0.5 flex items-center gap-1.5 text-xs">
                            <Crown
                                className="size-3.5 shrink-0 text-primary"
                                aria-hidden
                            />
                            {house ? (
                                <span>
                                    <span
                                        className="font-bold"
                                        style={{ color: house.colour }}
                                    >
                                        {house.name}
                                    </span>{' '}
                                    rules
                                    <span className="max-md:hidden">
                                        {' '}
                                        from the Farmhouse
                                    </span>
                                </span>
                            ) : (
                                'Nobody rules the Farmhouse yet'
                            )}
                        </p>
                    </div>
                    {!compact && (
                        <button
                            type="button"
                            onClick={() => {
                                setExpanded(!expanded);
                                setOpen(null);
                            }}
                            aria-expanded={expanded}
                            aria-label="Laws, land and unrest"
                            className="-mr-1 flex size-7 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-accent md:hidden"
                        >
                            <ChevronDown
                                className={cn(
                                    'size-4 transition-transform',
                                    expanded && 'rotate-180',
                                )}
                            />
                        </button>
                    )}
                </div>
                {!compact && (
                    <div
                        className={cn(
                            'mt-2 flex-wrap items-center gap-1.5 text-xs',
                            expanded ? 'flex' : 'hidden md:flex',
                        )}
                    >
                        <HudToggle
                            active={open === 'laws'}
                            onClick={() => toggle('laws')}
                        >
                            <ScrollText className="size-3.5" aria-hidden />
                            {laws.length} {laws.length === 1 ? 'law' : 'laws'}
                        </HudToggle>
                        <HudToggle
                            active={open === 'factions'}
                            onClick={() => toggle('factions')}
                        >
                            <Flag className="size-3.5" aria-hidden />
                            Who holds what
                        </HudToggle>
                        {season && (
                            <span className="ml-1">
                                <UnrestMeter unrest={season.unrest} />
                            </span>
                        )}
                    </div>
                )}
            </div>

            {!compact && open === 'laws' && (
                <div className="pointer-events-auto w-full rounded-xl border bg-card/95 p-3 text-sm shadow-lg backdrop-blur">
                    <p className="text-[11px] font-bold tracking-widest text-muted-foreground uppercase">
                        The Barn Wall says
                    </p>
                    {laws.length === 0 ? (
                        <p className="mt-1 text-muted-foreground">
                            No laws yet. Whoever takes the Farmhouse paints
                            them.
                        </p>
                    ) : (
                        <ol className="mt-1 flex list-decimal flex-col gap-1 pl-4">
                            {laws.map((law) => (
                                <li key={law.label}>
                                    <span className="font-semibold">
                                        {law.label}.
                                    </span>{' '}
                                    {law.wording}
                                </li>
                            ))}
                        </ol>
                    )}
                    <Link
                        href={wall()}
                        className="mt-2 inline-block font-semibold text-primary"
                    >
                        Go to the Barn Wall
                    </Link>
                </div>
            )}

            {!compact && open === 'factions' && (
                <div className="pointer-events-auto w-full rounded-xl border bg-card/95 p-3 text-sm shadow-lg backdrop-blur">
                    <p className="text-[11px] font-bold tracking-widest text-muted-foreground uppercase">
                        Land held
                    </p>
                    <ul className="mt-1 flex flex-col gap-1">
                        {standings.map(({ faction, count }) => (
                            <li
                                key={faction.id}
                                className="flex items-center gap-2"
                            >
                                <span
                                    className="size-2.5 shrink-0 rounded-full"
                                    style={{ background: faction.colour }}
                                />
                                <span className="min-w-0 flex-1 truncate font-semibold">
                                    {faction.name}
                                </span>
                                {house?.id === faction.id && (
                                    <Crown
                                        className="size-3.5 text-primary"
                                        aria-label="The House"
                                    />
                                )}
                                <span className="text-muted-foreground">
                                    {count} {count === 1 ? 'region' : 'regions'}
                                </span>
                            </li>
                        ))}
                        <li className="flex items-center gap-2 text-muted-foreground">
                            <span className="size-2.5 shrink-0 rounded-full border border-muted-foreground" />
                            <span className="flex-1">Unclaimed</span>
                            {unclaimed} {unclaimed === 1 ? 'region' : 'regions'}
                        </li>
                    </ul>
                </div>
            )}
        </div>
    );
}

function HudToggle({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-expanded={active}
            className={cn(
                'flex items-center gap-1 rounded-full border px-2 py-1 font-semibold transition-colors',
                active
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'hover:bg-accent',
            )}
        >
            {children}
        </button>
    );
}
