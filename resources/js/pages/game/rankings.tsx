import { Head, Link } from '@inertiajs/react';
import { Award, Coins, Palette, Trophy } from 'lucide-react';
import { GameBar } from '@/components/game/game-bar';
import { SkillMeter } from '@/components/game/missions';
import { OrchardBadge } from '@/components/game/orchard-badge';
import { speciesEmoji } from '@/lib/game';
import { cn } from '@/lib/utils';
import { rankings } from '@/routes/play';
import type { SkillCard, Species } from '@/types/game';

type Board = 'market' | 'creativity' | 'rosettes';

type Row = {
    rank: number;
    id: number;
    name: string;
    species: Species;
    faction: { name: string; colour: string } | null;
    orchard: boolean;
    points: number;
    title: string;
    me: boolean;
};

type Props = {
    board: Board;
    scope: 'season' | 'all';
    season: number | null;
    rows: Row[];
    me: Row | null;
    skills: SkillCard[];
};

const BOARDS: {
    value: Board;
    label: string;
    icon: typeof Coins;
    about: string;
}[] = [
    {
        value: 'market',
        label: 'Market',
        icon: Coins,
        about: 'The best traders: points for every sale, more for bigger ones, and for buying too.',
    },
    {
        value: 'creativity',
        label: 'Creativity',
        icon: Palette,
        about: "The best makers: points when a design passes its check, and every time a copy sells, even when it's resold.",
    },
    {
        value: 'rosettes',
        label: 'Rosettes',
        icon: Award,
        about: 'The most decorated animals in the valley, for everything they do.',
    },
];

const MEDALS = ['#f5c542', '#c9d1d9', '#d4925a'];

function RankRow({ row }: { row: Row }) {
    return (
        <li
            className={cn(
                'flex items-center gap-3 px-4 py-2.5',
                row.me && 'bg-primary/10',
            )}
        >
            <span
                className="w-8 shrink-0 text-right font-mono text-sm font-bold"
                style={{ color: MEDALS[row.rank - 1] }}
            >
                {row.rank}
            </span>
            <span aria-hidden className="text-lg">
                {speciesEmoji(row.species)}
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-semibold">
                    {row.name}
                    <OrchardBadge member={row.orchard} />
                    {row.me && (
                        <span className="ml-1.5 text-xs font-bold text-primary">
                            YOU
                        </span>
                    )}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {row.title}
                    {row.faction && (
                        <>
                            {' · '}
                            <span style={{ color: row.faction.colour }}>
                                {row.faction.name}
                            </span>
                        </>
                    )}
                </span>
            </span>
            <span className="shrink-0 font-mono text-sm font-bold">
                {row.points.toLocaleString('en-ZA')}
            </span>
        </li>
    );
}

/** The leaderboards: traders, makers and Rosettes, this season or all time. */
export default function Rankings({
    board,
    scope,
    season,
    rows,
    me,
    skills,
}: Props) {
    const current =
        BOARDS.find((option) => option.value === board) ?? BOARDS[0];
    const meShown = rows.some((row) => row.me);

    return (
        <>
            <Head title="Rankings" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <GameBar />

                <header className="flex flex-col gap-1">
                    <h1 className="flex items-center gap-2 text-3xl font-extrabold tracking-tight">
                        <Trophy className="size-7 text-primary" aria-hidden />
                        Rankings
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {current.about}
                    </p>
                </header>

                <div className="grid gap-3 sm:grid-cols-2">
                    {skills.map((skill) => (
                        <SkillMeter key={skill.skill} skill={skill} />
                    ))}
                </div>

                <nav
                    aria-label="Leaderboards"
                    className="flex flex-wrap items-center justify-between gap-2"
                >
                    <div className="flex rounded-full border p-1">
                        {BOARDS.map((option) => (
                            <Link
                                key={option.value}
                                href={rankings({
                                    query: { board: option.value, scope },
                                })}
                                preserveScroll
                                aria-current={
                                    option.value === board ? 'page' : undefined
                                }
                                className={cn(
                                    'flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold',
                                    option.value === board
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <option.icon className="size-4" aria-hidden />
                                {option.label}
                            </Link>
                        ))}
                    </div>
                    <div className="flex rounded-full border p-1 text-sm">
                        {(['season', 'all'] as const).map((value) => (
                            <Link
                                key={value}
                                href={rankings({
                                    query: { board, scope: value },
                                })}
                                preserveScroll
                                aria-current={
                                    value === scope ? 'page' : undefined
                                }
                                className={cn(
                                    'rounded-full px-3 py-1.5 font-semibold',
                                    value === scope
                                        ? 'bg-secondary text-secondary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {value === 'season'
                                    ? season
                                        ? `Season ${season}`
                                        : 'This season'
                                    : 'All time'}
                            </Link>
                        ))}
                    </div>
                </nav>

                {rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nobody has any points here yet. Be the first.
                    </p>
                ) : (
                    <ol className="divide-y overflow-hidden rounded-xl border bg-card">
                        {rows.map((row) => (
                            <RankRow key={row.id} row={row} />
                        ))}
                    </ol>
                )}

                {me && !meShown && (
                    <section
                        aria-label="Your place"
                        className="flex flex-col gap-2"
                    >
                        <p className="text-sm text-muted-foreground">
                            Your place
                        </p>
                        <ol className="overflow-hidden rounded-xl border bg-card">
                            <RankRow row={me} />
                        </ol>
                    </section>
                )}
            </div>
        </>
    );
}
