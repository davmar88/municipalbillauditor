import { useId } from 'react';
import type { BannerData } from '@/types/game';

export type { BannerData };

/** The shapes a banner can wear, by name, as drawn on a 60 x 80 cloth. */
function Pattern({ pattern, charge }: { pattern: string; charge: string }) {
    switch (pattern) {
        case 'stripes':
            return (
                <>
                    {[0, 2, 4].map((i) => (
                        <rect
                            key={i}
                            x={i * 10 + 10}
                            y="0"
                            width="10"
                            height="80"
                            fill={charge}
                        />
                    ))}
                </>
            );
        case 'chevron':
            return <path d="M0 52L30 26L60 52V68L30 42L0 68z" fill={charge} />;
        case 'cross':
            return (
                <>
                    <rect x="24" y="0" width="12" height="80" fill={charge} />
                    <rect x="0" y="30" width="60" height="12" fill={charge} />
                </>
            );
        case 'saltire':
            return (
                <path
                    d="M0 0h10L60 70v10H50L0 10zM60 0v10L10 80H0V70L50 0z"
                    fill={charge}
                />
            );
        case 'quarters':
            return (
                <>
                    <rect x="30" y="0" width="30" height="36" fill={charge} />
                    <rect x="0" y="36" width="30" height="44" fill={charge} />
                </>
            );
        case 'bend':
            return <path d="M0 0h16L60 60v20H44L0 20z" fill={charge} />;
        case 'border':
            return (
                <path
                    d="M0 0h60v80H0zM7 7v66h46V7z"
                    fill={charge}
                    fillRule="evenodd"
                />
            );
        case 'chief':
            return <rect x="0" y="0" width="60" height="22" fill={charge} />;
        default:
            return null;
    }
}

/** A faction's banner: its pattern and emblem, or the picture it uploaded. */
export function FactionBanner({
    banner,
    name,
    size = 48,
}: {
    banner: BannerData;
    name: string;
    size?: number;
}) {
    const clip = useId().replace(/:/g, '');
    const width = size * 0.75;
    const [field, charge] = banner.colours;

    if (banner.mode === 'image' && banner.image) {
        return (
            <img
                src={banner.image}
                alt={`${name}'s banner`}
                width={width}
                height={size}
                className="shrink-0 rounded-sm object-cover shadow-sm"
                style={{ width, height: size }}
            />
        );
    }

    return (
        <svg
            viewBox="0 0 60 80"
            width={width}
            height={size}
            role="img"
            aria-label={`${name}'s banner`}
            className="shrink-0 drop-shadow-sm"
        >
            <defs>
                <clipPath id={clip}>
                    {/* A swallow-tailed cloth. */}
                    <path d="M0 0h60v80L30 66L0 80z" />
                </clipPath>
            </defs>
            <g clipPath={`url(#${clip})`}>
                <rect width="60" height="80" fill={field} />
                <Pattern pattern={banner.pattern} charge={charge} />
                {banner.emblem && (
                    <text
                        x="30"
                        y="40"
                        textAnchor="middle"
                        dominantBaseline="central"
                        fontSize="24"
                    >
                        {banner.emblem}
                    </text>
                )}
            </g>
            <path
                d="M0 0h60v80L30 66L0 80z"
                fill="none"
                stroke="rgba(0,0,0,0.35)"
                strokeWidth="1.5"
            />
        </svg>
    );
}
