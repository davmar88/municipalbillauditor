import { memo } from 'react';
import type { WeatherKey } from '@/types/game';

/** A steady scatter, the same on every visit: no flicker on reload. */
function scatter(count: number, seed: number): number[][] {
    let s = seed;
    const next = () => {
        s = (s * 9301 + 49297) % 233280;

        return s / 233280;
    };

    return Array.from({ length: count }, () => [
        next(),
        next(),
        next(),
        next(),
    ]);
}

const LOOK: Record<
    WeatherKey,
    {
        count: number;
        glyphs: string[];
        tint: string;
        fall: number;
        sway: number;
        size: [number, number];
    }
> = {
    spring: {
        count: 14,
        glyphs: ['🌸', '·'],
        tint: 'rgba(120, 220, 140, 0.04)',
        fall: 16,
        sway: 40,
        size: [9, 14],
    },
    summer: {
        count: 10,
        glyphs: ['✦'],
        tint: 'rgba(255, 200, 60, 0.07)',
        fall: 0,
        sway: 0,
        size: [6, 10],
    },
    fall: {
        count: 22,
        glyphs: ['🍂', '🍁'],
        tint: 'rgba(230, 120, 40, 0.08)',
        fall: 12,
        sway: 70,
        size: [12, 18],
    },
    winter: {
        count: 60,
        glyphs: ['❄', '•'],
        tint: 'rgba(200, 225, 255, 0.10)',
        fall: 9,
        sway: 30,
        size: [6, 13],
    },
};

/**
 * The time of year over the map, flat or 3D: snow in winter, leaves in
 * fall, blossom in spring and a warm shimmer in summer. It never takes a
 * tap, and stays still for anyone who asks for less motion.
 */
export const WeatherOverlay = memo(function WeatherOverlay({
    now,
}: {
    now: WeatherKey;
}) {
    const look = LOOK[now];
    const phone =
        typeof window !== 'undefined' &&
        (window.matchMedia?.('(pointer: coarse)').matches ?? false);
    // A phone gets only the season's tint: no falling flakes or leaves, no wind.
    const bits = phone ? [] : scatter(look.count, now.length * 977);

    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 z-[5] overflow-hidden rounded-2xl"
            style={{ background: look.tint }}
        >
            <style>{`
                @keyframes weather-fall {
                    from { transform: translate3d(0, -10vh, 0) rotate(0deg); }
                    to { transform: translate3d(var(--sway), 110vh, 0) rotate(360deg); }
                }
                @keyframes weather-wind {
                    0% { transform: translateX(-30vw) scaleX(0.6); opacity: 0; }
                    15% { opacity: 0.55; }
                    85% { opacity: 0.4; }
                    100% { transform: translateX(130vw) scaleX(1.2); opacity: 0; }
                }
                @keyframes weather-glint {
                    0%, 100% { opacity: 0; transform: scale(0.6); }
                    50% { opacity: 0.8; transform: scale(1.1); }
                }
                @media (prefers-reduced-motion: reduce) {
                    .weather-bit { animation: none !important; display: none; }
                }
            `}</style>
            {now === 'summer' && (
                <div
                    className="absolute -top-24 -right-24 size-72 rounded-full"
                    style={{
                        background:
                            'radial-gradient(circle, rgba(255,220,120,0.35), rgba(255,200,80,0) 70%)',
                    }}
                />
            )}
            {/* The wind, gusting across the valley and the sea. */}
            {(phone ? [] : scatter(7, 4242)).map(
                ([y, delay, speed, size], i) => (
                    <span
                        key={`wind-${i}`}
                        className="weather-bit absolute left-0 h-px rounded-full"
                        style={{
                            top: `${(8 + y * 84).toFixed(1)}%`,
                            width: `${(80 + size * 140).toFixed(0)}px`,
                            background:
                                'linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,0.7), rgba(255,255,255,0))',
                            animation: `weather-wind ${(5 + speed * 5).toFixed(2)}s ease-in ${(delay * 9).toFixed(2)}s infinite`,
                        }}
                    />
                ),
            )}
            {bits.map(([x, delay, speed, size], i) => (
                <span
                    key={i}
                    className="weather-bit absolute top-0 select-none"
                    style={
                        {
                            left: `${(x * 100).toFixed(2)}%`,
                            top:
                                now === 'summer'
                                    ? `${(speed * 90).toFixed(1)}%`
                                    : undefined,
                            fontSize: `${(look.size[0] + size * (look.size[1] - look.size[0])).toFixed(1)}px`,
                            color:
                                now === 'winter'
                                    ? 'rgba(255,255,255,0.85)'
                                    : '#ffe9a8',
                            opacity: now === 'winter' ? 0.55 + size * 0.4 : 0.9,
                            '--sway': `${(look.sway + size * look.sway * 2).toFixed(0)}px`,
                            animation:
                                now === 'summer'
                                    ? `weather-glint ${(2.5 + speed * 3).toFixed(2)}s ease-in-out ${(delay * 4).toFixed(2)}s infinite`
                                    : `weather-fall ${(look.fall * (0.7 + speed * 0.8)).toFixed(2)}s linear ${(-delay * look.fall).toFixed(2)}s infinite`,
                        } as React.CSSProperties
                    }
                >
                    {look.glyphs[i % look.glyphs.length]}
                </span>
            ))}
        </div>
    );
});
