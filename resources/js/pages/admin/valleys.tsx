import { Head } from '@inertiajs/react';
import { Mountain } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { act } from '@/lib/game';
import { store, update } from '@/routes/admin/valleys';

type ValleyRow = {
    id: number;
    name: string;
    /** Animals active this week: what fills a valley. */
    active: number;
    total: number;
    factions: number;
    season: number | null;
    closed: boolean;
    takingNewcomers: boolean;
    openedAt: string | null;
};

type Props = {
    valleys: ValleyRow[];
    fullAt: number;
    nextName: string;
};

/**
 * The owner's valleys. Newcomers start in the oldest valley with room; when
 * every valley is full or closed, the next one opens by itself.
 */
export default function ValleysPage({ valleys, fullAt, nextName }: Props) {
    return (
        <>
            <Head title="Valleys" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-1">
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Mountain className="size-6" aria-hidden /> Valleys
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        The rules: a valley takes newcomers until{' '}
                        {fullAt.toLocaleString('en-ZA')} of its animals were
                        active this week. Newcomers start in the oldest valley
                        with room. When every valley is full or closed, the next
                        one ({nextName}) opens by itself, with its own map and
                        season, and you get a notice. Animals already in a
                        valley stay where they are.
                    </p>
                </header>

                <ul className="flex flex-col gap-3">
                    {valleys.map((valley) => {
                        const share = Math.min(1, valley.active / fullAt);

                        return (
                            <li
                                key={valley.id}
                                className="flex flex-col gap-3 rounded-xl border bg-card p-4"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-lg font-bold">
                                        {valley.name}
                                    </h2>
                                    {valley.takingNewcomers && (
                                        <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-bold text-emerald-500">
                                            Newcomers start here
                                        </span>
                                    )}
                                    {valley.closed && (
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-bold text-muted-foreground">
                                            Closed to newcomers
                                        </span>
                                    )}
                                    {!valley.closed &&
                                        valley.active >= fullAt && (
                                            <span className="rounded-full bg-orange-500/15 px-2 py-0.5 text-xs font-bold text-orange-500">
                                                Full
                                            </span>
                                        )}
                                </div>
                                <div
                                    className="h-2 overflow-hidden rounded-full bg-muted"
                                    role="progressbar"
                                    aria-valuenow={valley.active}
                                    aria-valuemax={fullAt}
                                    aria-label={`${valley.name}: ${valley.active} of ${fullAt} active`}
                                >
                                    <div
                                        className="h-full rounded-full bg-primary"
                                        style={{ width: `${share * 100}%` }}
                                    />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {valley.active.toLocaleString('en-ZA')}{' '}
                                    active this week of{' '}
                                    {fullAt.toLocaleString('en-ZA')} ·{' '}
                                    {valley.total.toLocaleString('en-ZA')}{' '}
                                    animals in all · {valley.factions}{' '}
                                    {valley.factions === 1
                                        ? 'faction'
                                        : 'factions'}
                                    {valley.season
                                        ? ` · season ${valley.season}`
                                        : ''}
                                </p>
                                <div>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() =>
                                            act(
                                                update.url(valley.id),
                                                {
                                                    closed: !valley.closed,
                                                },
                                                'put',
                                            )
                                        }
                                    >
                                        {valley.closed
                                            ? 'Take newcomers again'
                                            : 'Close to newcomers'}
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>

                <section className="flex flex-col gap-2 rounded-xl border border-dashed p-4">
                    <h2 className="font-bold">Open {nextName} now</h2>
                    <p className="text-sm text-muted-foreground">
                        Only if you want it before the rules open it. It starts
                        empty, with its own map and first season, and takes
                        newcomers once every valley before it is full or closed.
                    </p>
                    <div>
                        <Button size="sm" onClick={() => act(store.url())}>
                            Open {nextName}
                        </Button>
                    </div>
                </section>
            </div>
        </>
    );
}
