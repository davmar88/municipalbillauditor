import { fromNow } from '@/lib/game';
import { cn } from '@/lib/utils';
import type { Weather, WeatherKey } from '@/types/game';

export const WEATHER_COLOURS: Record<WeatherKey, string> = {
    spring: '#4ade80',
    summer: '#facc15',
    fall: '#f97316',
    winter: '#93c5fd',
};

/**
 * Where we are in the season, above the map: spring, summer, fall and
 * winter in a row, a marker for now, and what this time of year means.
 */
export function SeasonBar({ weather }: { weather: Weather }) {
    const index = weather.times.findIndex((time) => time.key === weather.now);
    const next = weather.times[index + 1];
    const until = next?.startsAt ?? weather.endsAt;

    return (
        <section
            aria-label="The time of year"
            className="flex flex-col gap-2 rounded-xl border bg-card px-4 py-3"
        >
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <p className="text-base font-bold">
                    <span aria-hidden>{weather.emoji}</span> {weather.label}
                </p>
                <p className="order-last w-full text-sm text-muted-foreground sm:order-none sm:w-auto sm:min-w-0 sm:flex-1">
                    {weather.note}
                </p>
                {until && (
                    <p className="text-xs text-muted-foreground">
                        {next
                            ? `${next.emoji} ${next.label} ${fromNow(until)}`
                            : `The season ends ${fromNow(until)}`}
                    </p>
                )}
            </div>
            <div className="relative">
                <div className="flex h-3 overflow-hidden rounded-full">
                    {weather.times.map((time) => (
                        <div
                            key={time.key}
                            className={cn(
                                'flex-1 transition-opacity',
                                time.key === weather.now
                                    ? 'opacity-100'
                                    : 'opacity-35',
                            )}
                            style={{ background: WEATHER_COLOURS[time.key] }}
                            title={`${time.label}: ${time.note}`}
                        />
                    ))}
                </div>
                <div
                    className="absolute -top-1 h-5 w-1.5 -translate-x-1/2 rounded-full border border-background bg-foreground shadow"
                    style={{ left: `${(weather.progress * 100).toFixed(2)}%` }}
                    aria-label={`${Math.round(weather.progress * 100)}% through the season`}
                />
                <div className="mt-1 flex text-[11px] text-muted-foreground">
                    {weather.times.map((time) => (
                        <span key={time.key} className="flex-1">
                            {time.emoji} {time.label}
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}
