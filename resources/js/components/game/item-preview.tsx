import type { DesignCard } from '@/types';

const WOOD = '#8b6b4a';
const METAL = '#a7a2b3';

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
    const fill = `url(#${id})`;

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
            <Shape template={design.template} fill={fill} second={second} />
        </svg>
    );
}

function Shape({
    template,
    fill,
    second,
}: {
    template: DesignCard['template'];
    fill: string;
    second: string;
}) {
    switch (template) {
        case 'crown':
            return (
                <>
                    <path
                        d="M10 46V22l11 12 11-16 11 16 11-12v24z"
                        fill={fill}
                    />
                    <rect x="10" y="44" width="44" height="6" fill={second} />
                </>
            );
        case 'ring':
            return (
                <>
                    <circle
                        cx="32"
                        cy="38"
                        r="15"
                        fill="none"
                        stroke={fill}
                        strokeWidth="6"
                    />
                    <path d="M26 20l6-8 6 8-6 4z" fill={second} />
                </>
            );
        case 'pie':
            return (
                <>
                    <ellipse cx="32" cy="40" rx="24" ry="10" fill={WOOD} />
                    <path d="M8 38c4-12 44-12 48 0z" fill={fill} />
                    <path
                        d="M18 34l6-4M30 32l6-4M42 34l6-4"
                        stroke={second}
                        strokeWidth="2"
                    />
                </>
            );
        case 'stew':
            return (
                <>
                    <path d="M10 30h44l-6 22H16z" fill={fill} />
                    <ellipse cx="32" cy="30" rx="22" ry="5" fill={second} />
                    <path
                        d="M24 18c2 4-2 6 0 10M38 18c2 4-2 6 0 10"
                        stroke="#c8c3d4"
                        strokeWidth="2"
                        fill="none"
                    />
                </>
            );
        case 'mortar':
            // A sack of mortar and a trowel.
            return (
                <>
                    <path d="M16 22h32l-4 32H20z" fill={fill} />
                    <path d="M16 22h32" stroke={second} strokeWidth="4" />
                    <path d="M40 12l12 12-6 2-8-8z" fill={second} />
                </>
            );
        case 'skin':
            // A home in its new paint.
            return (
                <>
                    <rect x="14" y="28" width="36" height="24" fill={fill} />
                    <path d="M8 30L32 10l24 20z" fill={second} />
                    <rect x="28" y="38" width="8" height="14" fill={WOOD} />
                </>
            );
        case 'hat':
            return (
                <>
                    <ellipse cx="32" cy="44" rx="26" ry="7" fill={fill} />
                    <path d="M18 44c0-18 6-26 14-26s14 8 14 26z" fill={fill} />
                    <rect x="18" y="36" width="28" height="5" fill={second} />
                </>
            );
        case 'scarf':
            return (
                <>
                    <path d="M12 18h40v12H12z" fill={fill} />
                    <path d="M38 28h12v26l-6-4-6 4z" fill={fill} />
                    <path
                        d="M38 50l6-4 6 4"
                        stroke={second}
                        strokeWidth="2"
                        fill="none"
                    />
                </>
            );
        case 'sign':
            return (
                <>
                    <rect
                        x="8"
                        y="14"
                        width="48"
                        height="28"
                        rx="3"
                        fill={fill}
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
                        stroke={WOOD}
                        strokeWidth="4"
                    />
                </>
            );
        case 'gloves':
            return (
                <>
                    <rect
                        x="9"
                        y="18"
                        width="20"
                        height="30"
                        rx="9"
                        fill={fill}
                    />
                    <ellipse cx="9" cy="34" rx="4" ry="7" fill={fill} />
                    <rect
                        x="9"
                        y="44"
                        width="20"
                        height="8"
                        rx="2"
                        fill={second}
                    />
                    <rect
                        x="35"
                        y="18"
                        width="20"
                        height="30"
                        rx="9"
                        fill={fill}
                    />
                    <ellipse cx="55" cy="34" rx="4" ry="7" fill={fill} />
                    <rect
                        x="35"
                        y="44"
                        width="20"
                        height="8"
                        rx="2"
                        fill={second}
                    />
                </>
            );
        case 'shoes':
            return (
                <>
                    <path
                        d="M17 12v22a15 15 0 0 0 30 0V12h-9v22a6 6 0 0 1-12 0V12z"
                        fill={fill}
                        stroke={METAL}
                        strokeWidth="1.5"
                    />
                    {[
                        [21.5, 18],
                        [21.5, 28],
                        [42.5, 18],
                        [42.5, 28],
                    ].map(([cx, cy]) => (
                        <circle
                            key={`${cx}-${cy}`}
                            cx={cx}
                            cy={cy}
                            r="1.8"
                            fill={second}
                        />
                    ))}
                </>
            );
        case 'cloak':
            return (
                <>
                    <path
                        d="M32 7c-8 0-12 6-12 12v4L9 57h46L44 23v-4c0-6-4-12-12-12z"
                        fill={fill}
                    />
                    <ellipse cx="32" cy="20" rx="7" ry="8" fill="#0b0a0f" />
                    <circle cx="32" cy="31" r="3" fill={second} />
                </>
            );
        case 'tonic':
            return (
                <>
                    <rect
                        x="27"
                        y="6"
                        width="10"
                        height="7"
                        rx="1.5"
                        fill={WOOD}
                    />
                    <rect
                        x="28.5"
                        y="13"
                        width="7"
                        height="9"
                        fill={METAL}
                        opacity="0.5"
                    />
                    <circle cx="32" cy="40" r="17" fill={fill} />
                    <circle
                        cx="32"
                        cy="40"
                        r="17"
                        fill="none"
                        stroke={METAL}
                        strokeWidth="1.5"
                    />
                    <rect
                        x="22"
                        y="36"
                        width="20"
                        height="9"
                        rx="2"
                        fill={second}
                    />
                </>
            );
        case 'ribbon':
            return (
                <>
                    <path
                        d="M24 32l-6 26 9-5 4 8 3-29zM40 32l6 26-9-5-4 8-3-29z"
                        fill={second}
                    />
                    <circle cx="32" cy="24" r="16" fill={fill} />
                    <circle cx="32" cy="24" r="8" fill={second} />
                    <circle
                        cx="32"
                        cy="24"
                        r="16"
                        fill="none"
                        stroke={second}
                        strokeWidth="2"
                        strokeDasharray="3 2"
                    />
                </>
            );
        case 'banner':
            return (
                <>
                    <path
                        d="M15 6v54"
                        stroke={WOOD}
                        strokeWidth="4"
                        strokeLinecap="round"
                    />
                    <path d="M17 9h36l-8 11 8 11H17z" fill={fill} />
                    <circle cx="31" cy="20" r="5" fill={second} />
                </>
            );
        case 'hedge':
            return (
                <>
                    {[
                        [18, 40, 12],
                        [32, 34, 14],
                        [46, 40, 12],
                        [25, 46, 10],
                        [40, 47, 10],
                    ].map(([cx, cy, r]) => (
                        <circle
                            key={`${cx}-${cy}`}
                            cx={cx}
                            cy={cy}
                            r={r}
                            fill={fill}
                        />
                    ))}
                    <path
                        d="M12 30l-3-4M24 22l-2-5M40 22l2-5M52 30l3-4M32 18v-5M8 44H3M56 44h5"
                        stroke={second}
                        strokeWidth="2.5"
                        strokeLinecap="round"
                    />
                </>
            );
        case 'basket':
            return (
                <>
                    <path
                        d="M14 30c0-14 36-14 36 0"
                        fill="none"
                        stroke={WOOD}
                        strokeWidth="3.5"
                    />
                    <circle cx="25" cy="30" r="6" fill={second} />
                    <circle cx="37" cy="29" r="6" fill={second} />
                    <circle
                        cx="31"
                        cy="25"
                        r="5"
                        fill={second}
                        opacity="0.85"
                    />
                    <path d="M8 32h48l-6 24H14z" fill={fill} />
                    <path
                        d="M12 40h40M14 48h36"
                        stroke="#0b0a0f"
                        strokeWidth="1.5"
                        opacity="0.35"
                    />
                </>
            );
        case 'bell':
            return (
                <>
                    <path d="M28 10a4 4 0 0 1 8 0v4h-8z" fill={METAL} />
                    <path d="M16 46c0-18 6-32 16-32s16 14 16 32z" fill={fill} />
                    <rect
                        x="12"
                        y="44"
                        width="40"
                        height="6"
                        rx="3"
                        fill={fill}
                        stroke={second}
                        strokeWidth="1.5"
                    />
                    <circle cx="32" cy="54" r="4.5" fill={second} />
                </>
            );
        case 'trowel':
            return (
                <>
                    <path
                        d="M14 50L36 20l10 10-30 22z"
                        fill={fill}
                        stroke={METAL}
                        strokeWidth="1.5"
                    />
                    <rect
                        x="40"
                        y="13"
                        width="7"
                        height="10"
                        rx="1.5"
                        transform="rotate(45 43.5 18)"
                        fill={second}
                    />
                    <path
                        d="M45 16l9-9"
                        stroke={WOOD}
                        strokeWidth="6"
                        strokeLinecap="round"
                    />
                </>
            );
        case 'cake':
            return (
                <>
                    <ellipse
                        cx="32"
                        cy="48"
                        rx="24"
                        ry="6"
                        fill="#0b0a0f"
                        opacity="0.5"
                    />
                    <path d="M10 30h44v16a22 6 0 0 1-44 0z" fill={fill} />
                    <ellipse cx="32" cy="30" rx="22" ry="7" fill={second} />
                    <path
                        d="M18 30c0 6 4 8 4 12M32 31c0 5 3 7 3 11M45 30c0 6-3 8-3 11"
                        stroke="#e3b341"
                        strokeWidth="3"
                        strokeLinecap="round"
                        fill="none"
                    />
                </>
            );
        case 'sword':
            return (
                <>
                    <path
                        d="M32 6l5 6v30h-10V12z"
                        fill={fill}
                        stroke={METAL}
                        strokeWidth="1.5"
                    />
                    <rect
                        x="20"
                        y="42"
                        width="24"
                        height="5"
                        rx="2"
                        fill={second}
                    />
                    <rect
                        x="29"
                        y="47"
                        width="6"
                        height="11"
                        rx="2"
                        fill={WOOD}
                    />
                </>
            );
        case 'pitchfork':
            return (
                <>
                    <path
                        d="M32 60V26"
                        stroke={WOOD}
                        strokeWidth="5"
                        strokeLinecap="round"
                    />
                    <path
                        d="M20 8v14a12 6 0 0 0 24 0V8M32 8v18"
                        fill="none"
                        stroke={fill}
                        strokeWidth="3.5"
                        strokeLinecap="round"
                    />
                </>
            );
        case 'cudgel':
            return (
                <>
                    <path
                        d="M24 58l6-30c1-8 4-16 10-20 6 0 8 6 6 12l-10 40z"
                        fill={fill}
                        stroke={WOOD}
                        strokeWidth="1.5"
                    />
                    <circle cx="38" cy="18" r="2" fill={second} />
                    <circle cx="35" cy="28" r="2" fill={second} />
                </>
            );
        case 'sling':
            return (
                <>
                    <path
                        d="M12 12c8 20 12 30 20 34 8-4 12-14 20-34"
                        fill="none"
                        stroke={fill}
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                    <ellipse cx="32" cy="46" rx="8" ry="5" fill={second} />
                    <circle cx="32" cy="45" r="3" fill={METAL} />
                </>
            );
        case 'pickaxe':
            return (
                <>
                    <path
                        d="M18 54L42 18"
                        stroke={WOOD}
                        strokeWidth="6"
                        strokeLinecap="round"
                    />
                    <path
                        d="M14 22c10-12 30-14 42-6-12-2-26 2-34 10z"
                        fill={fill}
                        stroke={METAL}
                        strokeWidth="1.5"
                    />
                    <circle cx="39" cy="21" r="3" fill={second} />
                </>
            );
        case 'lock':
            return (
                <>
                    <path
                        d="M22 30v-8a10 10 0 0 1 20 0v8"
                        fill="none"
                        stroke={METAL}
                        strokeWidth="5"
                    />
                    <rect
                        x="14"
                        y="28"
                        width="36"
                        height="28"
                        rx="5"
                        fill={fill}
                        stroke={METAL}
                        strokeWidth="1.5"
                    />
                    <circle cx="32" cy="40" r="4" fill={second} />
                    <path
                        d="M32 42v7"
                        stroke={second}
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                </>
            );
        case 'kit':
            return (
                <>
                    <rect
                        x="10"
                        y="20"
                        width="44"
                        height="34"
                        rx="5"
                        fill={fill}
                    />
                    <path
                        d="M24 20v-5h16v5"
                        fill="none"
                        stroke={METAL}
                        strokeWidth="3"
                    />
                    <path
                        d="M32 28v18M23 37h18"
                        stroke={second}
                        strokeWidth="6"
                        strokeLinecap="round"
                    />
                </>
            );
        case 'gate':
            return (
                <>
                    <path
                        d="M10 56V18M54 56V18"
                        stroke={METAL}
                        strokeWidth="5"
                        strokeLinecap="round"
                    />
                    <path d="M12 22h40v34H12z" fill={fill} opacity="0.9" />
                    <path
                        d="M20 22v34M28 22v34M36 22v34M44 22v34"
                        stroke="#0b0a0f"
                        strokeWidth="2"
                        opacity="0.5"
                    />
                    <path d="M12 38h40" stroke={second} strokeWidth="4" />
                </>
            );
        case 'speedo':
            return (
                <>
                    <path
                        d="M18 14h28l-4 42H22z"
                        fill={fill}
                        stroke={METAL}
                        strokeWidth="1.5"
                        strokeLinejoin="round"
                    />
                    <path d="M20 26h24" stroke={second} strokeWidth="4" />
                    <path
                        d="M38 6l-4 12"
                        stroke={second}
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                    <path
                        d="M28 34l6 6-6 6"
                        stroke="#0b0a0f"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        fill="none"
                        opacity="0.6"
                    />
                </>
            );
        case 'plough':
            return (
                <>
                    <path
                        d="M8 22h30l10 14"
                        stroke={WOOD}
                        strokeWidth="5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        fill="none"
                    />
                    <path
                        d="M40 30l16 18-24 6z"
                        fill={fill}
                        stroke={METAL}
                        strokeWidth="1.5"
                    />
                    <circle
                        cx="16"
                        cy="44"
                        r="9"
                        fill="none"
                        stroke={second}
                        strokeWidth="4"
                    />
                    <path
                        d="M16 35v18M7 44h18"
                        stroke={second}
                        strokeWidth="2"
                    />
                </>
            );
        case 'sheaf':
            return (
                <>
                    {[-12, -6, 0, 6, 12].map((dx) => (
                        <path
                            key={dx}
                            d={`M32 56L${32 + dx} 20`}
                            stroke={fill}
                            strokeWidth="3"
                            strokeLinecap="round"
                        />
                    ))}
                    {[-14, -7, 0, 7, 14].map((dx) => (
                        <ellipse
                            key={dx}
                            cx={32 + dx}
                            cy={16}
                            rx="4"
                            ry="8"
                            fill={fill}
                            transform={`rotate(${dx} ${32 + dx} 16)`}
                        />
                    ))}
                    <rect
                        x="22"
                        y="38"
                        width="20"
                        height="5"
                        rx="2"
                        fill={second}
                    />
                </>
            );
    }
}
