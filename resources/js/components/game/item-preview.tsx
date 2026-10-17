import type { DesignCard } from '@/types';

/** A player's design, drawn from its shape, two colours and pattern. */
export function ItemPreview({
    design,
    size = 72,
}: {
    design: Pick<DesignCard, 'template' | 'colours' | 'pattern' | 'name'>;
    size?: number;
}) {
    const [main, second] = design.colours;
    const id =
        `p-${design.template}-${design.pattern}-${main}-${second}`.replace(
            /[^a-z0-9-]/gi,
            '',
        );

    return (
        <svg
            viewBox="0 0 64 64"
            width={size}
            height={size}
            role="img"
            aria-label={design.name}
            className="shrink-0 rounded-md bg-[#14121a]"
        >
            <defs>
                <pattern
                    id={id}
                    width="8"
                    height="8"
                    patternUnits="userSpaceOnUse"
                >
                    <rect width="8" height="8" fill={main} />
                    {design.pattern === 'stripes' && (
                        <rect width="8" height="3" fill={second} />
                    )}
                    {design.pattern === 'dots' && (
                        <circle cx="4" cy="4" r="1.6" fill={second} />
                    )}
                    {design.pattern === 'checks' && (
                        <>
                            <rect width="4" height="4" fill={second} />
                            <rect
                                x="4"
                                y="4"
                                width="4"
                                height="4"
                                fill={second}
                            />
                        </>
                    )}
                    {design.pattern === 'stars' && (
                        <path
                            d="M4 1l.9 2.2L7 3.4 5.4 4.8 5.9 7 4 5.8 2.1 7l.5-2.2L1 3.4l2.1-.2z"
                            fill={second}
                        />
                    )}
                </pattern>
            </defs>
            {design.template === 'hat' && (
                <>
                    <ellipse
                        cx="32"
                        cy="44"
                        rx="26"
                        ry="7"
                        fill={`url(#${id})`}
                    />
                    <path
                        d="M18 44c0-18 6-26 14-26s14 8 14 26z"
                        fill={`url(#${id})`}
                    />
                    <rect x="18" y="36" width="28" height="5" fill={second} />
                </>
            )}
            {design.template === 'scarf' && (
                <>
                    <path d="M12 18h40v12H12z" fill={`url(#${id})`} />
                    <path d="M38 28h12v26l-6-4-6 4z" fill={`url(#${id})`} />
                    <path
                        d="M38 50l6-4 6 4"
                        stroke={second}
                        strokeWidth="2"
                        fill="none"
                    />
                </>
            )}
            {design.template === 'sign' && (
                <>
                    <rect
                        x="8"
                        y="14"
                        width="48"
                        height="28"
                        rx="3"
                        fill={`url(#${id})`}
                    />
                    <rect
                        x="8"
                        y="14"
                        width="48"
                        height="28"
                        rx="3"
                        fill="none"
                        stroke={second}
                        strokeWidth="3"
                    />
                    <path
                        d="M20 42v12M44 42v12"
                        stroke="#8b6b4a"
                        strokeWidth="4"
                    />
                </>
            )}
        </svg>
    );
}
