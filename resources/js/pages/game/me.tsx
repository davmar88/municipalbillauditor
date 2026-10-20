import { Head, Link } from '@inertiajs/react';
import { Award } from 'lucide-react';
import { AnimalStageSlot } from '@/components/game/animal-stage-slot';
import { GameBar } from '@/components/game/game-bar';
import { ItemPreview } from '@/components/game/item-preview';
import { OrchardBadge } from '@/components/game/orchard-badge';
import { NativeSelect } from '@/components/ui/native-select';
import { act, dayMonth } from '@/lib/game';
import { apples } from '@/routes/play';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    language as languageRoute,
    rename as nameRoute,
} from '@/routes/play/me';
import type { Worn } from '@/lib/animal-dress';
import type { DesignCard } from '@/types';
import type { Species } from '@/types/game';

type Props = {
    animal: {
        name: string;
        species: string;
        speciesLabel: string;
        role: string;
        talent: string;
        weakSpot: string;
        level: number;
        rosettes: number;
        levelFrom: number;
        nextLevelAt: number;
        seasonRosettes: number;
        wanted: number;
        joined: string;
        faction: string | null;
        role_in_faction: string | null;
        orchardUntil: string | null;
    };
    biography: string;
    /** The shop items it wears, one per spot on its body. */
    wearing: Worn[];
    rosettes: { amount: number; reason: string; at: string }[];
    collection: {
        items: { id: number; serial: number; design: DesignCard }[];
        kinds: number;
    };
    languages: string[];
    language: string;
    renameFrom: string | null;
};

export default function Me({
    animal,
    biography,
    wearing,
    rosettes,
    collection,
    languages,
    language,
    renameFrom,
}: Props) {
    const [name, setName] = useState(animal.name);
    const progress = Math.min(
        100,
        Math.round(
            ((animal.rosettes - animal.levelFrom) /
                Math.max(1, animal.nextLevelAt - animal.levelFrom)) *
                100,
        ),
    );

    return (
        <>
            <Head title={animal.name} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <GameBar />
                <header className="flex flex-col gap-2">
                    <p className="font-mono text-xs tracking-[0.14em] text-primary">
                        {animal.speciesLabel.toUpperCase()} ·{' '}
                        {animal.role.toUpperCase()}
                    </p>
                    <h1 className="text-3xl font-extrabold tracking-tight">
                        {animal.name}
                        <OrchardBadge member={animal.orchardUntil !== null} />
                    </h1>
                    {animal.orchardUntil && (
                        <p className="text-sm">
                            Orchard member until {dayMonth(animal.orchardUntil)}
                            .{' '}
                            <Link
                                href={apples()}
                                className="font-semibold text-primary"
                            >
                                Renew
                            </Link>
                        </p>
                    )}
                    <p className="text-muted-foreground">{biography}</p>
                </header>

                <AnimalStageSlot
                    species={animal.species as Species}
                    wearing={wearing}
                    empty="Hats, scarves, cloaks and the other shop items you own show on your animal."
                />

                <section className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border bg-card p-4">
                        <p className="flex items-center gap-2 font-bold">
                            <Award className="size-5 text-pink-300" /> Level{' '}
                            {animal.level}
                        </p>
                        <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full"
                                style={{
                                    width: `${progress}%`,
                                    backgroundImage:
                                        'linear-gradient(110deg, #41d1ff, #b39aff 50%, #d18cff)',
                                }}
                            />
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {animal.rosettes} Rosettes ·{' '}
                            {animal.nextLevelAt - animal.rosettes} to level{' '}
                            {animal.level + 1} · {animal.seasonRosettes} this
                            season
                        </p>
                    </div>
                    <div className="rounded-xl border bg-card p-4 text-sm">
                        <p className="font-bold">Talent</p>
                        <p>{animal.talent}</p>
                        <p className="mt-2 font-bold">Weak spot</p>
                        <p className="text-muted-foreground">
                            {animal.weakSpot}
                        </p>
                    </div>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Your collection</h2>
                    {collection.items.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No items yet. Buy one in the market or make your
                            own.
                        </p>
                    ) : (
                        <ul className="flex flex-wrap gap-3">
                            {collection.items.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-col items-center gap-1 text-xs"
                                >
                                    <ItemPreview
                                        design={item.design}
                                        size={64}
                                    />
                                    {item.design.name} #{item.serial}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Recent Rosettes</h2>
                    {rosettes.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Gather, trade, catch secret edits and win battles to
                            earn them.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1 text-sm">
                            {rosettes.map((entry, index) => (
                                <li
                                    key={index}
                                    className="flex justify-between gap-3"
                                >
                                    <span>{entry.reason}</span>
                                    <span className="font-semibold text-pink-300">
                                        +{entry.amount}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Your name</h2>
                    <form
                        className="flex max-w-md gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            act(nameRoute.url(), { name }, 'put');
                        }}
                    >
                        <Input
                            value={name}
                            maxLength={20}
                            aria-label="Your animal's name"
                            disabled={renameFrom !== null}
                            onChange={(e) => setName(e.target.value)}
                        />
                        <Button
                            type="submit"
                            disabled={
                                renameFrom !== null ||
                                name.trim() === '' ||
                                name.trim() === animal.name
                            }
                        >
                            Change
                        </Button>
                    </form>
                    <p className="text-sm text-muted-foreground">
                        {renameFrom
                            ? `You can change it again from ${dayMonth(renameFrom)}.`
                            : 'You can change it once a week. Your faction hears about it.'}
                    </p>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Chat translations</h2>
                    <NativeSelect
                        className="max-w-xs"
                        value={language}
                        aria-label="Translate chat into"
                        onChange={(e) =>
                            act(
                                languageRoute.url(),
                                { language: e.target.value },
                                'put',
                            )
                        }
                    >
                        {languages.map((l) => (
                            <option key={l} value={l}>
                                {l}
                            </option>
                        ))}
                    </NativeSelect>
                </section>
            </div>
        </>
    );
}
