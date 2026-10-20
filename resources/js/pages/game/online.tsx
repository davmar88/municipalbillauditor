import { Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { GameBar } from '@/components/game/game-bar';
import { speciesEmoji } from '@/lib/game';
import { animal as profile } from '@/routes/play';
import type { Species } from '@/types/game';

type OnlineAnimal = {
    id: number;
    name: string;
    species: Species;
    level: number;
    faction: { name: string; colour: string } | null;
    /** Where it stands, unless it's hiding. */
    where: string | null;
};

type Props = {
    valley: string;
    animals: OnlineAnimal[];
    total: number;
    minutes: number;
};

/** Who's in the valley right now: every animal seen in the last few minutes. */
export default function OnlinePage({ valley, animals, total, minutes }: Props) {
    return (
        <>
            <Head title="Who's online" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <GameBar />
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Users className="size-6" aria-hidden /> Who's online
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {animals.length.toLocaleString('en-ZA')} of{' '}
                        {total.toLocaleString('en-ZA')} animals in {valley} were
                        about in the last {minutes} minutes.
                    </p>
                </header>

                {animals.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                        Nobody else is about right now.
                    </p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {animals.map((animal) => (
                            <li key={animal.id}>
                                <Link
                                    href={profile(animal.id)}
                                    className="flex items-center gap-3 rounded-xl border bg-card px-3 py-2 hover:border-primary/60"
                                >
                                    <span className="text-2xl" aria-hidden>
                                        {speciesEmoji(animal.species)}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-bold">
                                            {animal.name}
                                            <span className="ml-1.5 text-xs font-semibold text-muted-foreground">
                                                level {animal.level}
                                            </span>
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {animal.faction ? (
                                                <span
                                                    style={{
                                                        color: animal.faction
                                                            .colour,
                                                    }}
                                                >
                                                    {animal.faction.name}
                                                </span>
                                            ) : (
                                                'Lone wolf'
                                            )}
                                            {animal.where
                                                ? ` · ${animal.where}`
                                                : ''}
                                        </span>
                                    </span>
                                    <span
                                        className="size-2 rounded-full bg-emerald-500"
                                        aria-label="Online"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
