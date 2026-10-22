import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    Crown,
    Flame,
    Heart,
    Medal,
    Pin,
    Swords,
    Trophy,
} from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { act, clockTime, fromNow, speciesEmoji } from '@/lib/game';
import { play } from '@/routes';
import { animal as profile } from '@/routes/play';
import { join as joinEvent } from '@/routes/play/event';
import { show as factionProfile } from '@/routes/play/factions';
import { bring } from '@/routes/play/job';
import { adopt, feed } from '@/routes/play/pet';
import type { Species } from '@/types/game';

type Prize = { corn: number; rosettes: number };

type Props = {
    season: {
        number: number;
        endsAt: string;
        finalDays: boolean;
        finalFrom: string;
    } | null;
    podium: {
        factions: { id: number; name: string; colour: string; land: number }[];
        animals: {
            id: number;
            name: string;
            species: Species;
            colour: string | null;
            rosettes: number;
        }[];
        prizes: { faction: Prize; animals: Record<string, Prize> };
    };
    hall: {
        season: number;
        faction: {
            id: number | null;
            name: string;
            colour: string | null;
            land: number;
        } | null;
        animals: {
            id: number | null;
            name: string;
            colour: string | null;
            place: number;
            rosettes: number;
        }[];
    }[];
    rivalry: {
        board: {
            id: number;
            name: string;
            colour: string;
            raids: number;
            stolen: number;
            land: number;
            points: number;
        }[];
        weekOf: string;
        last: {
            id: number | null;
            name: string;
            colour: string | null;
            points: number;
            weekOf: string | null;
        } | null;
        scoring: { raid: number; land: number; corn: number };
    };
    events: {
        id: number;
        kind: string;
        label: string;
        emoji: string;
        says: string;
        costs: string;
        pays: string;
        regionId: number;
        region: string;
        endsAt: string;
        left: number;
        joined: boolean;
    }[];
    jobs: {
        id: number;
        kind: string;
        poster: string;
        says: string;
        target: { id: number; name: string; colour: string } | null;
        apples: number;
        rosettes: number;
        expiresAt: string;
        doneBy: string | null;
        mine: boolean;
        canBring: boolean;
    }[];
    pet: {
        pet: {
            kind: string;
            label: string;
            emoji: string;
            name: string;
            level: number;
            max: number;
            feedsToNext: number | null;
            bonus: number;
            happy: boolean;
            does: string;
            canFeedAt: string | null;
        } | null;
        kinds: {
            kind: string;
            label: string;
            emoji: string;
            corn: number;
            per: number;
            does: string;
        }[];
        feedCorn: number;
    };
};

const MEDALS = ['🥇', '🥈', '🥉'];

/** "3 days 4 hours", or "5 hours 10 minutes" near the end. */
function countdown(to: string): string {
    const ms = Math.max(0, Date.parse(to) - Date.now());
    const minutes = Math.floor(ms / 60000);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    if (days > 0) {
        return `${days} ${days === 1 ? 'day' : 'days'} ${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    return `${hours} ${hours === 1 ? 'hour' : 'hours'} ${minutes % 60} minutes`;
}

function Section({
    title,
    icon: Icon,
    note,
    children,
}: {
    title: string;
    icon: typeof Trophy;
    note?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="flex items-center gap-2 text-lg font-bold">
                    <Icon className="size-5 text-primary" aria-hidden />
                    {title}
                </h2>
                {note && (
                    <p className="text-xs text-muted-foreground">{note}</p>
                )}
            </div>
            {children}
        </section>
    );
}

function Finale({ season, podium }: Pick<Props, 'season' | 'podium'>) {
    if (!season) {
        return null;
    }

    return (
        <Section
            title={`Season ${season.number} ends in ${countdown(season.endsAt)}`}
            icon={CalendarClock}
            note={`Ends ${clockTime(season.endsAt)}, ${new Date(season.endsAt).toLocaleDateString()}`}
        >
            {season.finalDays ? (
                <p className="flex items-center gap-2 rounded-lg bg-orange-500/10 px-3 py-2 text-sm font-semibold text-orange-300">
                    <Flame className="size-4" aria-hidden /> The final days:
                    every raid, defence and night at the gate pays double
                    Rosettes.
                </p>
            ) : (
                <p className="text-sm text-muted-foreground">
                    The final days start {fromNow(season.finalFrom)}: then every
                    battle pays double Rosettes.
                </p>
            )}
            <div className="grid gap-4 md:grid-cols-2">
                <div className="flex flex-col gap-2">
                    <h3 className="font-semibold">Factions by land held</h3>
                    {podium.factions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nobody holds land yet.
                        </p>
                    ) : (
                        <ol className="flex flex-col gap-1 text-sm">
                            {podium.factions.map((row, i) => (
                                <li
                                    key={row.id}
                                    className="flex justify-between gap-2 rounded-lg border px-3 py-1.5"
                                >
                                    <Link
                                        href={factionProfile(row.id)}
                                        className="font-semibold"
                                        style={{ color: row.colour }}
                                    >
                                        {MEDALS[i]} {row.name}
                                    </Link>
                                    <span>{row.land} regions</span>
                                </li>
                            ))}
                        </ol>
                    )}
                    <p className="text-xs text-muted-foreground">
                        The winner's members each get{' '}
                        {podium.prizes.faction.corn} Corn and{' '}
                        {podium.prizes.faction.rosettes} Rosettes, and a place
                        in the Hall of Fame.
                    </p>
                </div>
                <div className="flex flex-col gap-2">
                    <h3 className="font-semibold">
                        Animals by Rosettes this season
                    </h3>
                    {podium.animals.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No Rosettes won yet this season.
                        </p>
                    ) : (
                        <ol className="flex flex-col gap-1 text-sm">
                            {podium.animals.map((row, i) => (
                                <li
                                    key={row.id}
                                    className="flex justify-between gap-2 rounded-lg border px-3 py-1.5"
                                >
                                    <Link
                                        href={profile(row.id)}
                                        className="font-semibold"
                                        style={
                                            row.colour
                                                ? { color: row.colour }
                                                : undefined
                                        }
                                    >
                                        {MEDALS[i]} {speciesEmoji(row.species)}{' '}
                                        {row.name}
                                    </Link>
                                    <span>{row.rosettes} Rosettes</span>
                                </li>
                            ))}
                        </ol>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Prizes:{' '}
                        {Object.entries(podium.prizes.animals)
                            .map(
                                ([place, prize]) =>
                                    `${MEDALS[Number(place) - 1]} ${prize.corn} Corn and ${prize.rosettes} Rosettes`,
                            )
                            .join(', ')}
                        .
                    </p>
                </div>
            </div>
        </Section>
    );
}

function HallOfFame({ hall }: Pick<Props, 'hall'>) {
    return (
        <Section title="Hall of Fame" icon={Crown}>
            {hall.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Empty for now. The first season's winners go here for good.
                </p>
            ) : (
                <ul className="flex flex-col gap-2 text-sm">
                    {hall.map((row) => (
                        <li
                            key={row.season}
                            className="flex flex-col gap-1 rounded-lg border px-3 py-2"
                        >
                            <span className="font-semibold">
                                Season {row.season}
                                {row.faction && (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <span
                                            style={
                                                row.faction.colour
                                                    ? {
                                                          color: row.faction
                                                              .colour,
                                                      }
                                                    : undefined
                                            }
                                        >
                                            👑 {row.faction.name}
                                        </span>{' '}
                                        ({row.faction.land} regions)
                                    </>
                                )}
                            </span>
                            {row.animals.length > 0 && (
                                <span className="text-muted-foreground">
                                    {row.animals
                                        .map(
                                            (animal) =>
                                                `${MEDALS[animal.place - 1]} ${animal.name} (${animal.rosettes})`,
                                        )
                                        .join('  ')}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function RivalryBoard({ rivalry }: Pick<Props, 'rivalry'>) {
    return (
        <Section
            title="This week's rivalry"
            icon={Swords}
            note={`${rivalry.scoring.raid} points a raid won, ${rivalry.scoring.land} a region held, 1 per ${rivalry.scoring.corn} Corn stolen`}
        >
            {rivalry.last && (
                <p className="flex items-center gap-2 text-sm">
                    <Medal className="size-4 text-primary" aria-hidden />
                    Last week's winner:{' '}
                    <span
                        className="font-semibold"
                        style={
                            rivalry.last.colour
                                ? { color: rivalry.last.colour }
                                : undefined
                        }
                    >
                        {rivalry.last.name}
                    </span>{' '}
                    with {rivalry.last.points} points
                </p>
            )}
            {rivalry.board.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No factions yet.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1 pr-2">Faction</th>
                                <th className="px-2 text-right">Raids won</th>
                                <th className="px-2 text-right">Corn stolen</th>
                                <th className="px-2 text-right">Land</th>
                                <th className="pl-2 text-right">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rivalry.board.map((row, i) => (
                                <tr key={row.id} className="border-t">
                                    <td className="py-1.5 pr-2">
                                        <Link
                                            href={factionProfile(row.id)}
                                            className="font-semibold"
                                            style={{ color: row.colour }}
                                        >
                                            {i === 0 && row.points > 0
                                                ? '🏅 '
                                                : ''}
                                            {row.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 text-right">
                                        {row.raids}
                                    </td>
                                    <td className="px-2 text-right">
                                        {row.stolen}
                                    </td>
                                    <td className="px-2 text-right">
                                        {row.land}
                                    </td>
                                    <td className="pl-2 text-right font-bold">
                                        {row.points}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                The week runs Monday to Sunday. On Monday the winner goes into
                the Hall of Fame and the whole valley hears.
            </p>
        </Section>
    );
}

function Events({ events }: Pick<Props, 'events'>) {
    return (
        <Section
            title="Happening now"
            icon={Flame}
            note="Two a day, without warning. The first 5 animals there win."
        >
            {events.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    All quiet. A bell rings when something happens: a gold rush,
                    wolves, a flood or a travelling merchant.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {events.map((event) => (
                        <li
                            key={event.id}
                            className="flex flex-col gap-1.5 rounded-lg border p-3 text-sm"
                        >
                            <span className="font-semibold">
                                {event.emoji} {event.label} at {event.region}
                            </span>
                            <span className="text-muted-foreground">
                                {event.says} Costs {event.costs}; pays{' '}
                                {event.pays}. {event.left} of 5 places left,
                                until {clockTime(event.endsAt)}.
                            </span>
                            {event.joined ? (
                                <span className="font-semibold text-primary">
                                    You took part.
                                </span>
                            ) : event.left > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            act(joinEvent.url(event.id))
                                        }
                                    >
                                        Take part
                                    </Button>
                                    <Button size="sm" variant="ghost" asChild>
                                        <Link href={play()}>
                                            Walk there on the map
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                <span className="text-muted-foreground">
                                    All places taken.
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function Jobs({ jobs }: Pick<Props, 'jobs'>) {
    return (
        <Section
            title="Bounty board"
            icon={Pin}
            note="Jobs the valley animals pinned up. The first animal to do one is paid."
        >
            {jobs.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nothing pinned up right now.
                </p>
            ) : (
                <ul className="grid gap-2 sm:grid-cols-2">
                    {jobs.map((job) => (
                        <li
                            key={job.id}
                            className={`flex flex-col gap-1 rounded-lg border p-3 text-sm ${job.doneBy ? 'opacity-60' : ''}`}
                        >
                            <span className="font-semibold">📌 {job.says}</span>
                            <span className="text-xs text-muted-foreground">
                                Posted by {job.poster} · pays {job.apples}{' '}
                                {job.apples === 1 ? 'Apple' : 'Apples'} and{' '}
                                {job.rosettes} Rosettes
                                {job.doneBy
                                    ? ` · done by ${job.doneBy}`
                                    : ` · comes down ${fromNow(job.expiresAt)}`}
                            </span>
                            {job.mine && !job.doneBy && (
                                <span className="text-xs text-orange-300">
                                    That's your own faction: someone may come
                                    for you.
                                </span>
                            )}
                            {job.kind === 'bring' && !job.doneBy && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    className="self-start"
                                    disabled={!job.canBring}
                                    onClick={() => act(bring.url(job.id))}
                                >
                                    Hand it in from your bag
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function PetCard({ pet }: Pick<Props, 'pet'>) {
    const [name, setName] = useState('');
    const mine = pet.pet;

    return (
        <Section
            title={mine ? `${mine.emoji} ${mine.name}` : 'A pet of your own'}
            icon={Heart}
            note={`Feed it every 12 hours for ${pet.feedCorn} Corn. Unfed for 2 days, it sulks.`}
        >
            {mine ? (
                <div className="flex flex-col gap-2 text-sm">
                    <p>
                        Your {mine.label.toLowerCase()}, level {mine.level} of{' '}
                        {mine.max}.{' '}
                        {mine.happy
                            ? `You ${mine.does === 'walks' ? 'walk' : 'gather'} ${mine.bonus}% ${mine.does === 'walks' ? 'faster' : 'more'} with it.`
                            : 'It is sulking and helps with nothing until you feed it.'}
                    </p>
                    {mine.feedsToNext !== null && (
                        <p className="text-muted-foreground">
                            {mine.feedsToNext}{' '}
                            {mine.feedsToNext === 1 ? 'feed' : 'feeds'} to the
                            next level.
                        </p>
                    )}
                    <Button
                        size="sm"
                        className="self-start"
                        disabled={
                            mine.canFeedAt !== null &&
                            Date.parse(mine.canFeedAt) > Date.now()
                        }
                        onClick={() => act(feed.url())}
                    >
                        Feed {mine.name} ({pet.feedCorn} Corn)
                    </Button>
                    {mine.canFeedAt !== null &&
                        Date.parse(mine.canFeedAt) > Date.now() && (
                            <p className="text-xs text-muted-foreground">
                                Full. Feed again at {clockTime(mine.canFeedAt)}.
                            </p>
                        )}
                </div>
            ) : (
                <div className="flex flex-col gap-3 text-sm">
                    <label className="flex flex-col gap-1">
                        Name it
                        <Input
                            value={name}
                            maxLength={20}
                            placeholder="Boxer"
                            onChange={(e) => setName(e.target.value)}
                        />
                    </label>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {pet.kinds.map((kind) => (
                            <div
                                key={kind.kind}
                                className="flex flex-col gap-1.5 rounded-lg border p-3"
                            >
                                <span className="font-semibold">
                                    {kind.emoji} {kind.label} · {kind.corn} Corn
                                </span>
                                <span className="text-muted-foreground">
                                    You{' '}
                                    {kind.does === 'walks' ? 'walk' : 'gather'}{' '}
                                    {kind.per}%{' '}
                                    {kind.does === 'walks' ? 'faster' : 'more'}{' '}
                                    for every level it grows, up to 5.
                                </span>
                                <Button
                                    size="sm"
                                    className="self-start"
                                    disabled={name.trim().length < 2}
                                    onClick={() =>
                                        act(adopt.url(), {
                                            kind: kind.kind,
                                            name: name.trim(),
                                        })
                                    }
                                >
                                    Take in a {kind.label.toLowerCase()}
                                </Button>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Section>
    );
}

/** The valley: the season's countdown and podium, the rivalry, events, the bounty board, your pet and the Hall of Fame. */
export default function ValleyPage(props: Props) {
    return (
        <>
            <Head title="The valley" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
                <GameBar showNextStep={false} />
                <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                    <Trophy className="size-6 text-primary" aria-hidden /> The
                    valley
                </h1>
                <Finale season={props.season} podium={props.podium} />
                <Events events={props.events} />
                <div className="grid gap-5 lg:grid-cols-2">
                    <Jobs jobs={props.jobs} />
                    <PetCard pet={props.pet} />
                </div>
                <RivalryBoard rivalry={props.rivalry} />
                <HallOfFame hall={props.hall} />
            </div>
        </>
    );
}
