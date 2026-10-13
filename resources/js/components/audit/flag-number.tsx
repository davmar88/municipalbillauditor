import { cn } from '@/lib/utils';

/** The finding's number, as marked on the bill and on its card. */
export function FlagNumber({
    number,
    size = 'md',
    className,
}: {
    number: number;
    size?: 'sm' | 'md';
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full bg-flag font-sans font-bold text-flag-foreground',
                size === 'sm'
                    ? 'size-[18px] text-[11px]'
                    : 'size-[22px] text-xs',
                className,
            )}
        >
            <span className="sr-only">Finding </span>
            {number}
        </span>
    );
}
