import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { TimelineEntry } from '@/types';

/**
 * What has happened to a dispute and what comes next. Escalation steps are
 * marked "only if needed" so nobody expects them by default.
 */
export function Timeline({ steps }: { steps: TimelineEntry[] }) {
    return (
        <ol className="flex flex-col">
            {steps.map((step, index) => (
                <li
                    key={step.key}
                    className="relative flex gap-3.5 pb-5 last:pb-0"
                >
                    {index < steps.length - 1 && (
                        <span
                            aria-hidden
                            className={cn(
                                'absolute top-6 bottom-0 left-[11px] w-0.5',
                                step.state === 'done'
                                    ? 'bg-primary'
                                    : 'border-l-2 border-dashed border-input bg-transparent',
                            )}
                        />
                    )}
                    <Marker state={step.state} />
                    <div className="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-3">
                        <div className="flex min-w-0 flex-col">
                            <span
                                className={cn(
                                    'leading-6 font-semibold',
                                    step.state === 'if_needed' &&
                                        'font-medium text-muted-foreground',
                                    step.state === 'upcoming' &&
                                        'text-foreground/80',
                                )}
                            >
                                {step.title}
                                {step.state === 'current' && (
                                    <span className="sr-only"> (now)</span>
                                )}
                            </span>
                            {step.detail && (
                                <span className="text-sm text-muted-foreground">
                                    {step.detail}
                                </span>
                            )}
                        </div>
                        {step.on && (
                            <span
                                className={cn(
                                    'figures text-xs tracking-wide text-muted-foreground uppercase',
                                    step.state === 'current' &&
                                        'font-semibold text-foreground',
                                )}
                            >
                                {step.state === 'current' ||
                                step.state === 'upcoming'
                                    ? `Due ${step.on}`
                                    : step.on}
                            </span>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}

function Marker({ state }: { state: TimelineEntry['state'] }) {
    if (state === 'done') {
        return (
            <span className="relative z-10 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                <Check className="size-3.5" strokeWidth={3} aria-hidden />
            </span>
        );
    }

    if (state === 'current') {
        return (
            <span className="relative z-10 flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-primary bg-card">
                <span className="size-2.5 rounded-full bg-primary" />
            </span>
        );
    }

    return (
        <span
            className={cn(
                'relative z-10 size-6 shrink-0 rounded-full border-2 bg-card',
                state === 'if_needed'
                    ? 'border-dashed border-muted-foreground/60'
                    : 'border-input',
            )}
        />
    );
}
