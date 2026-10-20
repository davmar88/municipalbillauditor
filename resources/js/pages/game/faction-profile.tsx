import { Head, Link } from '@inertiajs/react';
import { Crown } from 'lucide-react';
import { FactionBanner } from '@/components/game/faction-banner';
import { GameBar } from '@/components/game/game-bar';
import { capitalise, shortWhen, speciesEmoji } from '@/lib/game';
import { animal as profile, faction as myFactionPage } from '@/routes/play';
import type { BannerData, Species } from '@/types/game';

type Props = {
    faction: {
        id: number;
        name: string;
        colour: string;
        motto: string | null;
        banner: BannerData;
        isHouse: boolean;
        mine: boolean;
        rogue: boolean;
        founded: string | null;
        entryMode: string;
    };
    members: {
        id: number;
        name: string;
        species: Species;
        level: number;
        role: string | null;
    }[];
    land: { id: number; name: string; ring: string; produces: string | null }[];
    buildings: {
        kind: string;
        level: number;
        region: string;
        goingUp: boolean;
    }[];
    studies: { label: string; level: number }[];
    record: { raidsWon: number; raidsLost: number; held: number; lost: number };
};

/** Any faction's page: its banner, members, land, buildings, studies and record this season. */
export default function FactionProfilePage({
    faction,
    members,
    land,
    buildings,
    studies,
    record,
}: Props) {
    return (
        <>
            <Head title={faction.name} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <GameBar showNextStep={false} />
                <header className="flex flex-wrap items-end gap-4">
                    <FactionBanner
                        banner={faction.banner}
                        name={faction.name}
                        size={96}
                    />
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <h1
                            className="flex items-center gap-2 text-2xl font-extrabold tracking-tight"
                            style={{ color: faction.colour }}
                        >
                            {faction.isHouse && (
                                <Crown
                                    className="size-6"
                                    aria-label="The House"
                                />
                            )}
                            {faction.name}
                        </h1>
                        {faction.motto && (
                            <p className="text-muted-foreground italic">
                                "{faction.motto}"
                            </p>
                        )}
                        <p className="text-sm text-muted-foreground">
                            {faction.isHouse
                                ? 'The House, ruling the valley · '
                                : ''}
                            {members.length}{' '}
                            {members.length === 1 ? 'member' : 'members'} ·{' '}
                            {land.length}{' '}
                            {land.length === 1 ? 'region' : 'regions'}
                            {faction.rogue ? ' · runs rogue' : ''}
                            {faction.founded
                                ? ` · founded ${shortWhen(faction.founded)}`
                                : ''}
                        </p>
                        {faction.mine && (
                            <Link
                                href={myFactionPage()}
                                className="text-sm font-semibold text-primary"
                            >
                                Your faction: manage it →
                            </Link>
                        )}
                    </div>
                </header>

                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {(
                        [
                            ['Raids won', record.raidsWon],
                            ['Raids failed', record.raidsLost],
                            ['Held off', record.held],
                            ['Land lost', record.lost],
                        ] as const
                    ).map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-xl border bg-card p-3"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {label} this season
                            </dt>
                            <dd className="text-2xl font-extrabold">{value}</dd>
                        </div>
                    ))}
                </dl>

                <div className="grid gap-4 md:grid-cols-2">
                    <section className="flex flex-col gap-2">
                        <h2 className="text-lg font-bold">Members</h2>
                        <ul className="divide-y rounded-xl border">
                            {members.map((member) => (
                                <li key={member.id}>
                                    <Link
                                        href={profile(member.id)}
                                        className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-accent"
                                    >
                                        <span aria-hidden>
                                            {speciesEmoji(member.species)}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate font-semibold">
                                            {member.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            level {member.level}
                                        </span>
                                        {member.role &&
                                            member.role !== 'member' && (
                                                <span className="text-xs font-semibold text-primary uppercase">
                                                    {member.role}
                                                </span>
                                            )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <div className="flex flex-col gap-4">
                        <section className="flex flex-col gap-2">
                            <h2 className="text-lg font-bold">Land</h2>
                            {land.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    It holds no land yet.
                                </p>
                            ) : (
                                <ul className="flex flex-wrap gap-1.5">
                                    {land.map((region) => (
                                        <li
                                            key={region.id}
                                            className="rounded-full border px-2.5 py-0.5 text-sm"
                                        >
                                            {region.name}
                                            {region.produces && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {region.produces}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="flex flex-col gap-2">
                            <h2 className="text-lg font-bold">Buildings</h2>
                            {buildings.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No buildings yet.
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-1 text-sm">
                                    {buildings.map((building, i) => (
                                        <li
                                            key={i}
                                            className="flex justify-between gap-2 rounded-lg border px-3 py-1.5"
                                        >
                                            <span className="font-semibold">
                                                {building.kind} {building.level}
                                                {building.goingUp && (
                                                    <span className="ml-1 text-xs text-muted-foreground">
                                                        (going up)
                                                    </span>
                                                )}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {building.region}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="flex flex-col gap-2">
                            <h2 className="text-lg font-bold">Studies</h2>
                            <ul className="grid grid-cols-2 gap-1.5 text-sm">
                                {studies.map((study) => (
                                    <li
                                        key={study.label}
                                        className="flex justify-between rounded-lg border px-3 py-1.5"
                                    >
                                        <span>{capitalise(study.label)}</span>
                                        <span className="font-semibold">
                                            {study.level}/5
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
