import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Flag,
    MapPin,
    Shield,
    ShoppingBag,
    Swords,
    Trophy,
} from 'lucide-react';
import { GameBar } from '@/components/game/game-bar';
import {
    DailyMission,
    SkillMeter,
    StoryMission,
} from '@/components/game/missions';
import { clockTime, fromNow, speciesEmoji } from '@/lib/game';
import { play } from '@/routes';
import { faction, market, rankings } from '@/routes/play';
import type { MissionCard, SkillCard, Species } from '@/types/game';

type Props = {
    animal: {
        name: string;
        species: Species;
        speciesLabel: string;
        role: string;
        standingIn: string;
    };
    season: { number: number; endsAt: string } | null;
    missions: {
        story: MissionCard | null;
        chapters: { name: string; done: number; total: number }[];
        daily: MissionCard[];
    };
    skills: SkillCard[];
    shop: {
        shelves: number;
        soldToday: number;
        cornToday: number;
        recent: { id: number; name: string; price: number; at: string }[];
    };
    needs: { battles: number; requests: number; gate: boolean };
};

function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Good morning'
        : hour < 18
          ? 'Good afternoon'
          : 'Good evening';
}

/** Today: the first screen of a visit. What to do, and what needs you. */
export default function Today({
    animal,
    season,
    missions,
    skills,
    shop,
    needs,
}: Props) {
    const alerts = [
        needs.battles > 0 && {
            key: 'battles',
            icon: Swords,
            text:
                needs.battles === 1
                    ? 'A battle involving your faction is under way.'
                    : `${needs.battles} battles involving your faction are under way.`,
            href: play(),
            action: 'To the map',
        },
        needs.requests > 0 && {
            key: 'requests',
            icon: Flag,
            text:
                needs.requests === 1
                    ? 'An animal is asking to join your faction.'
                    : `${needs.requests} animals are asking to join your faction.`,
            href: faction(),
            action: 'Answer',
        },
        needs.gate && {
            key: 'gate',
            icon: Shield,
            text: "It's Gate Night. Ironfield's men raid at 19:00.",
            href: play(),
            action: 'Hold the gate',
        },
    ].filter((alert) => alert !== false);

    return (
        <>
            <Head title="Today" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <GameBar />

                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="font-mono text-xs tracking-[0.14em] text-primary">
                            {animal.speciesLabel.toUpperCase()} ·{' '}
                            {animal.role.toUpperCase()}
                        </p>
                        <h1 className="flex items-center gap-2 text-3xl font-extrabold tracking-tight">
                            <span aria-hidden>
                                {speciesEmoji(animal.species)}
                            </span>
                            {greeting()}, {animal.name}
                        </h1>
                        <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                            <MapPin className="size-4" aria-hidden /> You're in{' '}
                            {animal.standingIn}
                            {season &&
                                ` · Season ${season.number} ends ${fromNow(season.endsAt)}`}
                        </p>
                    </div>
                </header>

                {alerts.length > 0 && (
                    <ul className="flex flex-col gap-2" aria-label="Needs you">
                        {alerts.map((alert) => (
                            <li key={alert.key}>
                                <Link
                                    href={alert.href}
                                    className="flex items-center gap-3 rounded-xl border border-orange-500/40 bg-orange-500/10 px-4 py-3 text-sm hover:bg-orange-500/15"
                                >
                                    <alert.icon
                                        className="size-5 shrink-0 text-orange-300"
                                        aria-hidden
                                    />
                                    <span className="flex-1">{alert.text}</span>
                                    <span className="flex items-center gap-1 font-semibold text-orange-200">
                                        {alert.action}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <StoryMission
                    mission={missions.story}
                    chapters={missions.chapters}
                />

                <section
                    aria-labelledby="jobs-heading"
                    className="flex flex-col gap-3"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="jobs-heading" className="text-lg font-bold">
                            Today's jobs
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Picked for you. New jobs at midnight.
                        </p>
                    </div>
                    <ul className="grid gap-3 sm:grid-cols-3">
                        {missions.daily.map((mission) => (
                            <DailyMission
                                key={mission.id}
                                mission={mission}
                                species={animal.speciesLabel}
                            />
                        ))}
                    </ul>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section
                        aria-labelledby="skills-heading"
                        className="flex flex-col gap-3"
                    >
                        <div className="flex items-baseline justify-between gap-2">
                            <h2
                                id="skills-heading"
                                className="text-lg font-bold"
                            >
                                Your skills
                            </h2>
                            <Link
                                href={rankings()}
                                className="flex items-center gap-1 text-sm font-semibold text-primary"
                            >
                                <Trophy className="size-4" aria-hidden />{' '}
                                Rankings
                            </Link>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            {skills.map((skill) => (
                                <SkillMeter key={skill.skill} skill={skill} />
                            ))}
                        </div>
                    </section>

                    <section
                        aria-labelledby="shop-heading"
                        className="flex flex-col gap-3"
                    >
                        <div className="flex items-baseline justify-between gap-2">
                            <h2 id="shop-heading" className="text-lg font-bold">
                                Your shop
                            </h2>
                            <Link
                                href={market()}
                                className="flex items-center gap-1 text-sm font-semibold text-primary"
                            >
                                <ShoppingBag className="size-4" aria-hidden />{' '}
                                Market
                            </Link>
                        </div>
                        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                            <dl className="grid grid-cols-3 gap-2 text-center">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        On shelves
                                    </dt>
                                    <dd className="text-2xl font-extrabold">
                                        {shop.shelves}
                                        <span className="text-sm font-normal text-muted-foreground">
                                            /6
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Sold today
                                    </dt>
                                    <dd className="text-2xl font-extrabold">
                                        {shop.soldToday}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Corn today
                                    </dt>
                                    <dd className="text-2xl font-extrabold text-amber-300">
                                        {shop.cornToday.toLocaleString('en-ZA')}
                                    </dd>
                                </div>
                            </dl>
                            {shop.recent.length > 0 ? (
                                <ul className="divide-y border-t text-sm">
                                    {shop.recent.map((sale) => (
                                        <li
                                            key={sale.id}
                                            className="flex items-center justify-between gap-2 py-2"
                                        >
                                            <span className="truncate">
                                                {sale.name}
                                            </span>
                                            <span className="shrink-0 text-muted-foreground">
                                                {sale.price} Corn ·{' '}
                                                {clockTime(sale.at)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="border-t pt-3 text-sm text-muted-foreground">
                                    No sales yet. Make something in the Workshop
                                    and put it on your shelves.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
