import { usePage } from '@inertiajs/react';
import { Award, Clock3, Flame, Wheat } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { act, fromNow } from '@/lib/game';
import { cn } from '@/lib/utils';
import { rebuild } from '@/routes/play';

/** The strip along the top of every game screen: what you have to spend. */
export function GameBar() {
    const { player } = usePage().props;

    if (!player) {
        return null;
    }

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
                    {player.horseshoes}/{player.maxHorseshoes}
                    {nextAt && (
                        <span className="font-normal text-muted-foreground">
                            · next {fromNow(nextAt)}
                        </span>
                    )}
                </span>
                <span className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 font-semibold">
                    <Wheat className="size-4 text-amber-300" aria-hidden />
                    {player.corn.toLocaleString('en-ZA')} Corn
                </span>
                <span className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5 font-semibold">
                    <Award className="size-4 text-pink-300" aria-hidden />
                    Level {player.level}
                    <span className="font-normal text-muted-foreground">
                        · {player.rosettes} Rosettes
                    </span>
                </span>
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
