import { Heart } from 'lucide-react';
import { act } from '@/lib/game';
import { cn } from '@/lib/utils';
import { drink, eat, waterBarrel } from '@/routes/play';
import type { Vitals } from '@/types/game';

/** The colour a bar out of 100 takes: green, then amber, then red. */
export function healthColour(value: number): string {
    return value > 60 ? '#22c55e' : value > 30 ? '#f59e0b' : '#ef4444';
}

function Meter({
    icon,
    label,
    value,
    colour,
    onClick,
    title,
}: {
    icon: React.ReactNode;
    label: string;
    value: number;
    colour: string;
    onClick?: () => void;
    title: string;
}) {
    const Tag = onClick ? 'button' : 'span';

    return (
        <Tag
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            title={title}
            className={cn(
                'flex min-w-[8.5rem] flex-1 items-center gap-2 rounded-full border bg-card px-3 py-1.5 text-left sm:flex-none',
                onClick && 'hover:border-primary/60',
                value <= 20 && 'border-red-500/60',
            )}
        >
            <span aria-hidden className="shrink-0">
                {icon}
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex justify-between gap-2 text-xs leading-none">
                    <span className="font-semibold">{label}</span>
                    <span className="text-muted-foreground">{value}</span>
                </span>
                <span className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <span
                        className="block h-full rounded-full transition-[width] duration-700"
                        style={{ width: `${value}%`, background: colour }}
                    />
                </span>
            </span>
        </Tag>
    );
}

/**
 * Staying alive, on every game screen: health, how fed and how watered you
 * are. Tap the Corn to eat and the water to drink.
 */
export function VitalsBar({ vitals }: { vitals: Vitals }) {
    return (
        <div className="flex flex-wrap items-center gap-2 text-sm">
            <Meter
                icon={
                    <Heart
                        className="size-4"
                        style={{ color: healthColour(vitals.health) }}
                        fill="currentColor"
                    />
                }
                label="Health"
                value={vitals.health}
                colour={healthColour(vitals.health)}
                title="Your health. It falls when you're starving or parched, and mends when you're fed and watered. At nothing you die and drop everything you carry."
            />
            <Meter
                icon="🌽"
                label="Food"
                value={vitals.food}
                colour="#eab308"
                onClick={() => act(eat.url())}
                title="How fed you are. You eat the Corn you carry when you're peckish. Tap to eat your fill now."
            />
            <Meter
                icon="💧"
                label="Water"
                value={vitals.drink}
                colour="#38bdf8"
                onClick={() => act(drink.url())}
                title={
                    vitals.freeWater
                        ? `Tap to drink your fill at ${vitals.freeWater}, for free.`
                        : 'How watered you are. You drink the water you carry, then your faction’s store. Tap to drink now.'
                }
            />
            <span
                className="flex items-center gap-1.5 rounded-full border bg-card px-3 py-1.5"
                title={`Your ${vitals.barrel} holds ${vitals.waterCap} water.${vitals.store > 0 ? ` Your faction's store has ${vitals.store} more.` : ''}`}
            >
                <span aria-hidden>🪣</span>
                {vitals.water}/{vitals.waterCap}
                <span className="text-muted-foreground">{vitals.barrel}</span>
                {vitals.store > 0 && (
                    <span className="text-muted-foreground">
                        · store {vitals.store}
                    </span>
                )}
            </span>
            {vitals.nextBarrel && (
                <button
                    type="button"
                    onClick={() => act(waterBarrel.url())}
                    className="rounded-full border border-sky-500/40 bg-sky-500/10 px-3 py-1.5 text-xs font-semibold hover:border-sky-400"
                    title={`Carry up to ${vitals.nextBarrel.holds} water, for when fall dries the ponds up.`}
                >
                    {vitals.nextBarrel.name}: {vitals.nextBarrel.corn} Corn
                </button>
            )}
            {vitals.diedAt && (
                <span className="rounded-full border border-red-500/50 bg-red-500/10 px-3 py-1.5 text-xs font-semibold text-red-300">
                    💀 You died lately. What you carried lies on the map for
                    anyone to pick up.
                </span>
            )}
        </div>
    );
}
