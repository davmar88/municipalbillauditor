import { cn } from '@/lib/utils';

/** How many horseshoes to put into a raid, a defence or the gate. */
export function HorseshoePicker({
    value,
    onChange,
}: {
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <div
            className="flex overflow-hidden rounded-md border"
            role="group"
            aria-label="Horseshoes to put in"
        >
            {[1, 2, 3].map((count) => (
                <button
                    key={count}
                    type="button"
                    onClick={() => onChange(count)}
                    aria-pressed={value === count}
                    className={cn(
                        'flex h-9 w-9 items-center justify-center text-sm font-semibold',
                        value === count
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-transparent',
                    )}
                >
                    {count}
                </button>
            ))}
        </div>
    );
}
