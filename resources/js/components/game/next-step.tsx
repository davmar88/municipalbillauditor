import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Gift } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { act } from '@/lib/game';
import { missionPlace } from '@/lib/missions';
import { today } from '@/routes/play';
import { claim } from '@/routes/play/mission';

/**
 * A slim bar under the game bar on every screen: the story step you're on,
 * how far along you are, and the way to it, or the reward once it's done.
 */
export function NextStep() {
    const { nextStep } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();

    if (!nextStep || nextStep.locked) {
        return null;
    }

    // On the screen where the step is done, the bar only reminds; no Go button.
    const place = missionPlace(nextStep.key);
    const percent = Math.min(
        100,
        Math.round((nextStep.progress / Math.max(1, nextStep.target)) * 100),
    );

    return (
        <div
            className="flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-3 py-2"
            aria-label="Next step"
        >
            <Link
                href={today()}
                className="min-w-0 flex-1"
                title="See your story and today's jobs"
            >
                <span className="block truncate text-[11px] font-bold tracking-widest text-primary uppercase">
                    Next step · {nextStep.title}
                </span>
                <span className="block truncate text-sm">
                    {nextStep.goal}
                    {nextStep.target > 1 && (
                        <span className="text-muted-foreground">
                            {' '}
                            ({nextStep.progress}/{nextStep.target})
                        </span>
                    )}
                </span>
                <span className="mt-1 block h-1 overflow-hidden rounded-full bg-muted">
                    <span
                        className="block h-full rounded-full bg-primary transition-[width] duration-500"
                        style={{ width: `${percent}%` }}
                    />
                </span>
            </Link>
            {nextStep.done ? (
                <Button
                    size="sm"
                    onClick={() => act(claim.url(nextStep.id))}
                    className="shrink-0"
                >
                    <Gift className="size-4" /> Collect
                </Button>
            ) : isCurrentUrl(place.href) ? null : (
                <Button
                    size="sm"
                    variant="secondary"
                    asChild
                    className="shrink-0"
                >
                    <Link href={place.href}>
                        <span className="hidden sm:inline">{place.label}</span>
                        <span className="sm:hidden">Go</span>
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
            )}
        </div>
    );
}
