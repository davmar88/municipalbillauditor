import { Link, usePage } from '@inertiajs/react';
import { Award, Backpack, Clock3, Flame, Sparkles, Wheat } from 'lucide-react';
import { NextStep } from '@/components/game/next-step';
import { Button } from '@/components/ui/button';
import { act, fromNow } from '@/lib/game';
import { cn } from '@/lib/utils';
import { apples, rebuild } from '@/routes/play';
import { refill } from '@/routes/play/orchard';

/**
 * The strip along the top of every game screen: what you have to spend,
 * what you carry, and your next step in the story.
 */
export function GameBar({ showNextStep = true }: { showNextStep?: boolean }) {
    const { player } = usePage().props;

    if (!player) {
        return null;
    }

    const carried = Object.entries(player.materials ?? {}).filter(
        ([, count]) => count > 0,
    );

    const nextAt =
        player.nextHorseshoeIn !== null
            ? new Date(Date.now() + player.nextHorseshoeIn * 1000).toISOString()
            : null;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span
                    className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 font-semibold"
                    title="Horseshoes: every action costs one or more"
                >
                    <Horseshoe className="size-4 text-primary" />
                    {player.horseshoes > player.maxHorseshoes
                        ? player.horseshoes
                        : `${player.horseshoes}/${player.maxHorseshoes}`}
                    {nextAt && (
                        <span className="font-normal text-muted-foreground">
                            · next {fromNow(nextAt)}
                        </span>
                    )}
                </span>
                {player.freeRefill &&
                    player.horseshoes < player.maxHorseshoes && (
                        <button
                            type="button"
                            onClick={() => act(refill.url())}
                            className="flex items-center gap-1.5 rounded-full border border-emerald-500/50 bg-emerald-500/10 px-3 py-1.5 font-semibold hover:border-emerald-400"
                            title="Orchard members get one free horseshoe refill a day"
                        >
                            <span aria-hidden>🌳</span> Free refill
                        </button>
                    )}
                <span className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 font-semibold">
                    <Wheat className="size-4 text-amber-300" aria-hidden />
                    {player.corn.toLocaleString('en-ZA')} Corn
                </span>
                <Link
                    href={apples()}
                    prefetch
                    className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 font-semibold hover:border-primary/60"
                    title="Apples: swap Pineapples for packs, or swap Apples for Corn"
                >
                    <span aria-hidden>🍎</span>
                    {player.apples.toLocaleString('en-ZA')} Apples
                </Link>
                {player.pineapples > 0 && (
                    <Link
                        href={apples()}
                        prefetch
                        className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 font-semibold hover:border-primary/60"
                        title="Pineapples: swap them for packs of Apples"
                    >
                        <span aria-hidden>🍍</span>
                        {player.pineapples.toLocaleString('en-ZA')} Pineapples
                    </Link>
                )}
                <span className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 font-semibold">
                    <Award className="size-4 text-pink-300" aria-hidden />
                    Level {player.level}
                    <span className="font-normal text-muted-foreground">
                        · {player.rosettes} Rosettes
                    </span>
                </span>
                <span
                    className="flex max-w-full items-center gap-1.5 rounded-full border bg-card px-3 py-1.5"
                    title={
                        carried.length > 0
                            ? `You carry ${carried.map(([name, count]) => `${count} ${name}`).join(', ')}`
                            : 'You carry no materials yet. Gather them on the map.'
                    }
                >
                    <Backpack
                        className="size-4 shrink-0 text-cyan-300"
                        aria-hidden
                    />
                    {carried.length > 0 ? (
                        <span className="truncate">
                            {carried
                                .map(([name, count]) => `${count} ${name}`)
                                .join(' · ')}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            Nothing carried
                        </span>
                    )}
                </span>
                {(player.powers ?? []).map((power) => (
                    <span
                        key={power.kind}
                        className="flex items-center gap-1.5 rounded-full border border-primary/40 bg-primary/10 px-3 py-1.5"
                        title={`${power.label} is working`}
                    >
                        <Sparkles
                            className="size-4 shrink-0 text-primary"
                            aria-hidden
                        />
                        <span className="font-semibold">{power.label}</span>
                        <span className="text-muted-foreground">
                            · {power.left}
                        </span>
                    </span>
                ))}
            </div>
            {(player.inPoundUntil || player.homeClosedUntil) && (
                <p
                    className={cn(
                        'flex items-center gap-2 rounded-md border px-3 py-2 text-sm',
                        'border-orange-500/40 bg-orange-500/10 text-orange-200',
                    )}
                >
                    {player.inPoundUntil ? (
                        <>
                            <Clock3 className="size-4" aria-hidden /> You are in
                            the pound, free {fromNow(player.inPoundUntil)}.
                        </>
                    ) : (
                        <>
                            <Flame className="size-4" aria-hidden /> Your home
                            burned down. Your shop reopens{' '}
                            {fromNow(player.homeClosedUntil!)}.
                            <Button
                                size="sm"
                                variant="secondary"
                                className="ml-auto"
                                onClick={() => act(rebuild.url())}
                            >
                                Rebuild now (100 Corn)
                            </Button>
                        </>
                    )}
                </p>
            )}
            {showNextStep && <NextStep />}
        </div>
    );
}

export function Horseshoe({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" className={className} aria-hidden>
            <path
                d="M6 20V11a6 6 0 0 1 12 0v9"
                fill="none"
                stroke="currentColor"
                strokeWidth="3"
                strokeLinecap="round"
            />
        </svg>
    );
}
