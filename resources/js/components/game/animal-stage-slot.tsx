import { lazy, Suspense } from 'react';
import type { Worn } from '@/lib/animal-dress';
import { speciesEmoji } from '@/lib/game';
import type { Species } from '@/types/game';

// three.js and the models only load on the pages that show an animal.
const AnimalStage = lazy(() => import('@/components/game/animal-stage'));

/** The animal in 3D, with a line saying what it wears. */
export function AnimalStageSlot({
    species,
    wearing,
    empty,
}: {
    species: Species;
    wearing: Worn[];
    /** What to say when it wears nothing. */
    empty: string;
}) {
    return (
        <section className="flex flex-col gap-2">
            <Suspense
                fallback={
                    <div
                        className="flex h-64 w-full items-center justify-center rounded-xl border bg-card text-6xl sm:h-72"
                        aria-hidden
                    >
                        {speciesEmoji(species)}
                    </div>
                }
            >
                <AnimalStage species={species} wearing={wearing} />
            </Suspense>
            <p className="text-sm text-muted-foreground">
                {wearing.length > 0
                    ? `Wearing ${list(wearing.map((w) => w.name))}.`
                    : empty}
            </p>
        </section>
    );
}

function list(names: string[]): string {
    return names.length < 2
        ? names.join('')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}
