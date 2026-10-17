import { cn } from '@/lib/utils';

/** Anger in the valley: at 60 anyone can call an uprising. */
export function UnrestMeter({ unrest }: { unrest: number }) {
    return (
        <span
            className="flex items-center gap-2"
            title="Unrest: at 60 anyone can call an uprising"
        >
            <span className="text-muted-foreground">Unrest</span>
            <span className="relative h-2 w-24 overflow-hidden rounded-full bg-muted">
                <span
                    className={cn(
                        'absolute inset-y-0 left-0 rounded-full',
                        unrest >= 60 ? 'bg-orange-500' : 'bg-primary',
                    )}
                    style={{ width: `${unrest}%` }}
                />
                <span className="absolute inset-y-0 left-[60%] w-px bg-white/60" />
            </span>
            <span className="font-semibold">{unrest}</span>
        </span>
    );
}
