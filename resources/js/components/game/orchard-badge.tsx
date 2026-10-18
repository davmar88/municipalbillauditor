import { cn } from '@/lib/utils';

/** The badge by an Orchard member's name. */
export function OrchardBadge({
    member,
    className,
}: {
    member?: boolean;
    className?: string;
}) {
    if (!member) {
        return null;
    }

    return (
        <span
            role="img"
            aria-label="Orchard member"
            title="Orchard member"
            className={cn('ml-1 inline-block not-italic', className)}
        >
            🌳
        </span>
    );
}
