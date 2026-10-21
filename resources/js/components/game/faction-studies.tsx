import {
    BookOpen,
    FlaskConical,
    Footprints,
    Shield,
    Swords,
    Telescope,
    Wheat,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    CostList,
    duration,
    GiveShort,
    shortOf,
} from '@/components/game/faction-base';
import { Button } from '@/components/ui/button';
import { WhyNot, storeShort } from '@/components/game/why-not';
import { act, clockTime } from '@/lib/game';
import { cn } from '@/lib/utils';
import { study as startStudy } from '@/routes/play';
import type { FactionStudies, StudyValue } from '@/types/game';

const ICONS: Record<StudyValue, LucideIcon> = {
    attack: Swords,
    defence: Shield,
    science: FlaskConical,
    farming: Wheat,
    marching: Footprints,
    technology: Telescope,
};

type Store = { treasury: number; materials: Record<string, number> };

/**
 * Your faction's studies on the Faction page: what each one does, the next
 * level's cost and time, and the one under way.
 */
export function FactionStudyList({
    studies,
    store,
}: {
    studies: FactionStudies;
    store: Store;
}) {
    const busy = studies.studying !== null;

    return (
        <section className="flex flex-col gap-3">
            <div>
                <h2 className="flex items-center gap-2 text-lg font-bold">
                    <BookOpen className="size-5 text-primary" aria-hidden />{' '}
                    Studies
                </h2>
                <p className="text-sm text-muted-foreground">
                    Studies make your whole faction stronger for good, and stay
                    from season to season. Your faction studies one thing at a
                    time, paid from the treasury and the store; levels 3 to 5
                    need honey, herbs or iron from the farms beyond the fences.
                    Your faction has{' '}
                    <span className="font-semibold text-foreground">
                        {studies.points}{' '}
                        {studies.points === 1 ? 'study point' : 'study points'}
                    </span>
                    . Ironfield sends one more man to Gate Night for every point
                    the strongest faction has.
                </p>
            </div>

            {studies.studying && (
                <p className="flex items-center gap-2 rounded-lg border border-primary/40 bg-primary/10 px-3 py-2 text-sm">
                    <BookOpen
                        className="size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    Studying {studies.studying.label} {studies.studying.level}:
                    done at {clockTime(studies.studying.until)}.
                </p>
            )}

            <ul className="grid gap-3 sm:grid-cols-2">
                {studies.studies.map((card) => {
                    const Icon = ICONS[card.value];
                    const short = card.next ? shortOf(card.next, store) : [];
                    const underWay = studies.studying?.value === card.value;

                    return (
                        <li
                            key={card.value}
                            className="flex flex-col gap-2 rounded-xl border bg-card p-4"
                        >
                            <div className="flex items-center gap-2">
                                <Icon
                                    className="size-4 text-primary"
                                    aria-hidden
                                />
                                <h3 className="font-semibold">{card.label}</h3>
                                <Pips level={card.level} max={card.max} />
                            </div>
                            <p className="text-sm">
                                {card.does ?? 'Not studied yet.'}
                            </p>

                            {card.next === null ? (
                                <p className="text-xs font-semibold text-primary">
                                    Fully studied.
                                </p>
                            ) : (
                                <div className="flex flex-col gap-1.5 border-t pt-2">
                                    <p className="text-xs text-muted-foreground">
                                        Level {card.next.level}:{' '}
                                        {card.next.does} Takes{' '}
                                        {duration(card.next.minutes)}.
                                    </p>
                                    <CostList cost={card.next} store={store} />
                                    {studies.canStudy && (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                className="self-start"
                                                disabled={
                                                    busy || short.length > 0
                                                }
                                                onClick={() =>
                                                    act(startStudy.url(), {
                                                        study: card.value,
                                                    })
                                                }
                                            >
                                                {underWay
                                                    ? 'Studying now'
                                                    : `Study level ${card.next.level}`}
                                            </Button>
                                            <WhyNot
                                                reason={
                                                    busy && !underWay
                                                        ? 'Your faction studies one thing at a time. Wait for the study under way to finish.'
                                                        : storeShort(short)
                                                }
                                            />
                                            {card.next && !busy && (
                                                <GiveShort
                                                    cost={card.next}
                                                    store={store}
                                                />
                                            )}
                                        </>
                                    )}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            {!studies.canStudy && (
                <p className="text-xs text-muted-foreground">
                    Your leader and council start studies. Give Corn and
                    materials to the store to help pay for them.
                </p>
            )}
        </section>
    );
}

/** A dot for each level: filled once studied. */
function Pips({ level, max }: { level: number; max: number }) {
    return (
        <span
            className="ml-auto flex items-center gap-1"
            aria-label={`Level ${level} of ${max}`}
        >
            {Array.from({ length: max }, (_, i) => (
                <span
                    key={i}
                    className={cn(
                        'size-2 rounded-full border border-primary/60',
                        i < level && 'bg-primary',
                    )}
                />
            ))}
        </span>
    );
}
