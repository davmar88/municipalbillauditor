import { memo } from 'react';
import type { Point } from '@/lib/hollowmere';
import {
    BROOK,
    CITY,
    FIELD_SHAPES,
    FIELDS,
    SHAPES,
    WORLD,
    blob,
    fits,
    hub,
    scatter,
    scatterBox,
    seeded,
    smoothLoop,
    smoothPath,
    toPath,
} from '@/lib/hollowmere';

/**
 * The farm drawn as a dark street map in RevenueGuard's violet. Everything
 * here is drawn once; small things like trees and sheep are batched into a
 * few shapes so dragging the map stays smooth on phones.
 */
export const INK = {
    beyond: '#13151a',
    fence: '#463f55',
    fencePost: '#756d86',
    field: '#1e2a21',
    fieldRow: '#28382b',
    hay: '#2a291d',
    hayRow: '#393722',
    bale: '#6e5c2f',
    baleTop: '#86723b',
    meadow: '#1b2824',
    meadowDot: '#24352d',
    woods: '#142019',
    tree: ['#1b3023', '#203829', '#25402f'],
    treeTop: '#2f5039',
    shadow: '#07090a',
    yard: '#23212b',
    yardStone: '#2c2a36',
    rock: '#24232a',
    rockLight: '#35333d',
    water: '#132b47',
    waterEdge: '#2b5b88',
    bank: '#18282a',
    road: '#3f3b4b',
    roadMajor: '#4f495d',
    roadCasing: '#0b0a0f',
    roadLine: '#6f6881',
    path: '#6a6479',
    building: '#2e2a39',
    buildingEdge: '#4f4860',
    roof: '#3a3447',
    roofDark: '#312c3d',
    border: '#5d5670',
    hedge: '#0e1611',
    sand: '#2c2833',
    clay: '#4a3a30',
    stone: '#7b748b',
    wool: '#dcd8e6',
    hen: '#efe4c6',
    wall: '#e7e2d3',
} as const;

type Disc = { x: number; y: number; r: number };
type TreeSpec = Disc & { tone: number };

function at(key: string, dx: number, dy: number): Point {
    const [cx, cy] = SHAPES[key].centre;

    return [cx + dx, cy + dy];
}

function near(point: Point, other: Point, distance: number): boolean {
    return Math.hypot(point[0] - other[0], point[1] - other[1]) < distance;
}

/** Many circles as one shape. */
function discs(list: Disc[]): string {
    return list
        .map(({ x, y, r }) => {
            const radius = r.toFixed(1);

            return `M${(x - r).toFixed(1)} ${y.toFixed(1)}a${radius} ${radius} 0 1 0 ${(2 * r).toFixed(1)} 0a${radius} ${radius} 0 1 0 ${(-2 * r).toFixed(1)} 0`;
        })
        .join('');
}

function trees(
    points: Point[],
    key: string,
    size: [number, number],
): TreeSpec[] {
    return points.map(([x, y], i) => ({
        x,
        y,
        r: size[0] + seeded(`${key}:r:${i}`) * size[1],
        tone: i % 3,
    }));
}

/** Trees seen from above: shadow, canopy in three greens, and a lit top. */
function Trees({ list }: { list: TreeSpec[] }) {
    return (
        <g>
            <path
                d={discs(
                    list.map((t) => ({
                        x: t.x + t.r * 0.28,
                        y: t.y + t.r * 0.38,
                        r: t.r,
                    })),
                )}
                fill={INK.shadow}
                opacity={0.6}
            />
            {INK.tree.map((colour, tone) => (
                <path
                    key={colour}
                    d={discs(list.filter((t) => t.tone === tone))}
                    fill={colour}
                />
            ))}
            <path
                d={discs(
                    list.map((t) => ({
                        x: t.x - t.r * 0.3,
                        y: t.y - t.r * 0.32,
                        r: t.r * 0.48,
                    })),
                )}
                fill={INK.treeTop}
                opacity={0.75}
            />
        </g>
    );
}

function Bales({ list }: { list: Disc[] }) {
    return (
        <g>
            <path
                d={discs(
                    list.map((b) => ({ x: b.x + 1.5, y: b.y + 2, r: b.r })),
                )}
                fill={INK.shadow}
                opacity={0.6}
            />
            <path d={discs(list)} fill={INK.bale} />
            <path
                d={discs(list.map((b) => ({ ...b, r: b.r * 0.55 })))}
                fill="none"
                stroke={INK.baleTop}
                strokeWidth={1.2}
            />
        </g>
    );
}

function Building({
    at: [x, y],
    w,
    h,
    angle = 0,
    roof = INK.roof,
    edge = INK.buildingEdge,
}: {
    at: Point;
    w: number;
    h: number;
    angle?: number;
    roof?: string;
    edge?: string;
}) {
    return (
        <g
            transform={`translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${angle})`}
        >
            <rect
                x={-w / 2 + 3}
                y={-h / 2 + 4}
                width={w}
                height={h}
                rx={1.5}
                fill={INK.shadow}
                opacity={0.65}
            />
            <rect
                x={-w / 2}
                y={-h / 2}
                width={w}
                height={h}
                rx={1.5}
                fill={INK.roofDark}
                stroke={edge}
                strokeWidth={1.2}
            />
            <rect
                x={-w / 2 + 0.6}
                y={-h / 2 + 0.6}
                width={w - 1.2}
                height={h / 2 - 0.6}
                fill={roof}
            />
            <line
                x1={-w / 2 + 2}
                x2={w / 2 - 2}
                y1={0}
                y2={0}
                stroke={edge}
                strokeWidth={1.1}
            />
        </g>
    );
}

function Patterns() {
    const rows = (id: string, angle: number, base: string, row: string) => (
        <pattern
            id={id}
            width="9"
            height="9"
            patternUnits="userSpaceOnUse"
            patternTransform={`rotate(${angle})`}
        >
            <rect width="9" height="9" fill={base} />
            <rect width="9" height="3.4" fill={row} />
        </pattern>
    );

    return (
        <>
            {rows('rows-granary', 18, INK.field, INK.fieldRow)}
            {rows('rows-windmill_hill', -26, INK.field, INK.fieldRow)}
            {rows('rows-ironfield_gate', 66, INK.field, INK.fieldRow)}
            {rows('rows-hayfield', -10, INK.hay, INK.hayRow)}
            {rows('rows-thornbury', 8, INK.field, INK.fieldRow)}
            {rows('rows-brookside', -40, INK.hay, INK.hayRow)}
            {rows('rows-willowby', 30, INK.field, INK.fieldRow)}
            {rows('rows-ashcombe', -18, INK.field, INK.fieldRow)}
            {rows('rows-fennick', 52, INK.hay, INK.hayRow)}
            {rows('rows-marlow', -36, INK.hay, INK.hayRow)}
            <pattern
                id="meadow"
                width="36"
                height="36"
                patternUnits="userSpaceOnUse"
            >
                <rect width="36" height="36" fill={INK.meadow} />
                <path
                    d={discs([
                        { x: 5, y: 7, r: 1.5 },
                        { x: 21, y: 4, r: 1.1 },
                        { x: 30, y: 19, r: 1.7 },
                        { x: 12, y: 26, r: 1.3 },
                        { x: 26, y: 31, r: 1 },
                        { x: 3, y: 18, r: 0.9 },
                    ])}
                    fill={INK.meadowDot}
                />
            </pattern>
            <pattern
                id="cobbles"
                width="12"
                height="12"
                patternUnits="userSpaceOnUse"
            >
                <rect width="12" height="12" fill={INK.yard} />
                <path
                    d="M2.2 1h2.6a1.2 1.2 0 0 1 1.2 1.2v2a1.2 1.2 0 0 1-1.2 1.2H2.2A1.2 1.2 0 0 1 1 4.2v-2A1.2 1.2 0 0 1 2.2 1zM8.2 2h1.6a1.2 1.2 0 0 1 1.2 1.2v1.2a1.2 1.2 0 0 1-1.2 1.2H8.2A1.2 1.2 0 0 1 7 4.4V3.2A1.2 1.2 0 0 1 8.2 2zM4.2 7h2.6A1.2 1.2 0 0 1 8 8.2v1.6A1.2 1.2 0 0 1 6.8 11H4.2A1.2 1.2 0 0 1 3 9.8V8.2A1.2 1.2 0 0 1 4.2 7z"
                    fill={INK.yardStone}
                />
            </pattern>
            <pattern
                id="scree"
                width="30"
                height="30"
                patternUnits="userSpaceOnUse"
            >
                <rect width="30" height="30" fill={INK.rock} />
                <path
                    d="M4 6l4-2 3 3-3 3zM18 14l5-1 2 4-4 2-3-2zM8 22l3-1 2 3-3 2z"
                    fill={INK.rockLight}
                />
            </pattern>
            <pattern
                id="cobbles"
                width="14"
                height="14"
                patternUnits="userSpaceOnUse"
            >
                <rect width="14" height="14" fill="#211e28" />
                <path d="M1 1h5v5h-5zM8 1h5v5h-5zM4 8h5v5h-5z" fill="#2a2633" />
            </pattern>
            <pattern
                id="ironfield"
                width="10"
                height="10"
                patternUnits="userSpaceOnUse"
                patternTransform="rotate(45)"
            >
                <rect width="10" height="10" fill="#141218" />
                <rect width="10" height="2" fill="#1d1a24" />
            </pattern>
        </>
    );
}

/** What each region's ground is made of. */
const GROUND: Record<string, string> = {
    wildwood: INK.woods,
    orchard: 'url(#meadow)',
    big_barn: 'url(#cobbles)',
    hayfield: 'url(#rows-hayfield)',
    quarry: 'url(#scree)',
    sheepfold: 'url(#meadow)',
    granary: 'url(#rows-granary)',
    farmhouse: 'url(#cobbles)',
    windmill_hill: 'url(#rows-windmill_hill)',
    ironfield_gate: 'url(#rows-ironfield_gate)',
    duck_pond: 'url(#meadow)',
    henhouse: 'url(#cobbles)',
    market_square: 'url(#cobbles)',
    stables: 'url(#meadow)',
    old_well: 'url(#meadow)',
    paddock: 'url(#meadow)',
    thornbury: 'url(#rows-thornbury)',
    brookside: 'url(#rows-brookside)',
    willowby: 'url(#rows-willowby)',
    ashcombe: 'url(#rows-ashcombe)',
    fennick: 'url(#rows-fennick)',
    marlow: 'url(#rows-marlow)',
};

/** A building in Irongate: [x, y, width, depth, angle]. */
type Block = [number, number, number, number, number];

/** The town's buildings, by district: tenements, the yards' sheds, the Garrison's barracks. */
export const CITY_BLOCKS: Record<string, Block[]> = {
    irongate_rookery: [
        [1572, 168, 40, 22, -4],
        [1628, 152, 34, 20, 3],
        [1704, 160, 44, 24, -2],
        [1790, 176, 38, 22, 5],
        [1858, 214, 30, 24, -6],
        [1566, 300, 36, 20, -5],
        [1846, 300, 34, 22, 4],
        [1612, 330, 30, 16, 2],
    ],
    irongate_yards: [
        [1812, 404, 62, 30, 2],
        [1874, 520, 44, 40, -3],
        [1792, 572, 50, 22, 4],
        [1566, 400, 28, 20, -3],
    ],
    irongate_garrison: [
        [1784, 676, 92, 32, 0],
        [1788, 752, 92, 30, 0],
        [1572, 800, 34, 22, -4],
    ],
};

/** The Garrison's stockade, round its barracks. */
export const STOCKADE = { x: 1716, y: 640, width: 150, height: 150 };

/** Crates stacked in the Iron Yards. */
const CRATES: Point[] = [
    [1752, 458],
    [1764, 470],
    [1742, 474],
    [1836, 462],
    [1848, 474],
    [1690, 566],
];

/** Lamps lit at Irongate's gates and corners. */
export const LAMPS: Point[] = [
    [1530, 470],
    [1530, 230],
    [1530, 720],
    [1716, 640],
    [1866, 640],
    [1900, 350],
];

/**
 * Irongate, the humans' town past the east fence: cobbled districts inside a
 * stone wall, close-packed tenements in the Rookery, sheds, crates and a
 * crane in the Iron Yards, and the Garrison's stockade and watchtower. The
 * fence keeps buildings off Hollowmere itself; out here, men build.
 */
function Irongate() {
    const districts = Object.keys(CITY_BLOCKS).map((key) => SHAPES[key]);
    const blocks = Object.values(CITY_BLOCKS).flat();
    const { x, y, width, height } = STOCKADE;

    return (
        <>
            {districts.map((shape, i) => (
                <path
                    key={i}
                    d={toPath(shape.polygon)}
                    fill="url(#cobbles)"
                    stroke="#3d3646"
                    strokeWidth={2}
                />
            ))}
            {/* The town wall, broken by the gate onto Gate Road. */}
            {districts.map((shape, i) => (
                <path
                    key={`wall-${i}`}
                    d={toPath(shape.polygon)}
                    fill="none"
                    stroke="#5a5266"
                    strokeWidth={7}
                    strokeDasharray={i === 1 ? '120 40 2000' : undefined}
                    opacity={0.9}
                />
            ))}
            {blocks.map(([bx, by, w, h, angle], i) => (
                <Building
                    key={i}
                    at={[bx, by]}
                    w={w}
                    h={h}
                    angle={angle}
                    roof={i % 2 ? '#3a2f3a' : '#2f2a38'}
                    edge="#4a4256"
                />
            ))}
            <rect
                x={x}
                y={y}
                width={width}
                height={height}
                rx={6}
                fill="none"
                stroke="#6b5a44"
                strokeWidth={5}
                strokeDasharray="4 3"
            />
            {/* The watchtower and the crane. */}
            <rect
                x={x + width - 14}
                y={y - 14}
                width={22}
                height={22}
                fill="#2f2a38"
                stroke="#6b5a44"
                strokeWidth={2}
            />
            <path
                d="M1700 440l60-40M1700 440v40M1760 400l10 18"
                stroke="#8a7a5c"
                strokeWidth={3}
                fill="none"
            />
            {CRATES.map(([cx, cy], i) => (
                <rect
                    key={i}
                    x={cx - 5}
                    y={cy - 5}
                    width={10}
                    height={10}
                    fill="#6e5c2f"
                    stroke="#86723b"
                    strokeWidth={1}
                />
            ))}
            {LAMPS.map(([lx, ly], i) => (
                <circle key={i} cx={lx} cy={ly} r={3} fill="#ffb35c" />
            ))}
        </>
    );
}

/** The land around the farm: the Ridge, Hollow Brook and Ironfield. */
function Surroundings() {
    const edge = trees(
        [
            ...scatterBox(
                { x: -10, y: -14, width: 1620, height: 112 },
                110,
                'ridge',
            ),
            ...scatterBox(
                { x: -10, y: 60, width: 44, height: 960 },
                34,
                'west',
            ),
            ...scatterBox(
                { x: 40, y: 1046, width: 1560, height: 40 },
                46,
                'south',
            ),
            // The far edges of the valley, and the wild patches round each farm.
            ...scatterBox(
                { x: -720, y: -640, width: 2500, height: 160 },
                150,
                'outer-north',
            ),
            ...scatterBox(
                { x: -720, y: -500, width: 60, height: 2200 },
                70,
                'outer-west',
            ),
            ...scatterBox(
                { x: -720, y: 1610, width: 2500, height: 110 },
                130,
                'outer-south',
            ),
            ...scatterBox(
                { x: -660, y: -480, width: 280, height: 680 },
                70,
                'outer-north-west',
            ),
            ...scatterBox(
                { x: -660, y: 860, width: 260, height: 760 },
                70,
                'outer-south-west',
            ),
            ...Object.entries(FIELD_SHAPES).flatMap(([key, field]) =>
                scatter(field, 46, `wild:${key}`, 6),
            ),
            // The wild land round the farms beyond the fences.
            ...scatterBox(
                { x: -460, y: -400, width: 2200, height: 130 },
                130,
                'far-north',
            ),
            ...scatterBox(
                { x: -460, y: -280, width: 90, height: 1760 },
                70,
                'far-west',
            ),
            ...scatterBox(
                { x: -460, y: 1390, width: 2200, height: 90 },
                110,
                'far-south',
            ),
            ...scatterBox(
                { x: -370, y: -280, width: 360, height: 360 },
                60,
                'north-west',
            ),
            ...scatterBox(
                { x: -370, y: 900, width: 360, height: 480 },
                70,
                'south-west',
            ),
            ...scatterBox(
                { x: 1270, y: -280, width: 220, height: 370 },
                45,
                'north-east',
            ),
            ...scatterBox(
                { x: 1220, y: 1080, width: 280, height: 300 },
                45,
                'south-east',
            ),
            ...scatterBox(
                { x: 110, y: 1080, width: 270, height: 300 },
                45,
                'south-middle',
            ),
        ],
        'edge',
        [8, 7],
    );

    return (
        <g>
            <rect
                x={WORLD.x}
                y={WORLD.y}
                width={WORLD.width}
                height={WORLD.height}
                fill={INK.beyond}
            />
            <rect
                x={1490}
                y={WORLD.y}
                width={WORLD.x + WORLD.width - 1490}
                height={WORLD.height}
                fill="url(#ironfield)"
            />
            <path
                d={[26, 52, 78]
                    .map((y, i) =>
                        smoothPath(
                            Array.from(
                                { length: 9 },
                                (_, j) =>
                                    [
                                        j * 200 - 20,
                                        y +
                                            (seeded(`contour:${i}:${j}`) * 2 -
                                                1) *
                                                9,
                                    ] as Point,
                            ),
                        ),
                    )
                    .join('')}
                fill="none"
                stroke="#1b1f25"
                strokeWidth={1.4}
            />
            <Trees list={edge} />
            {/* Hollow Brook. */}
            <path
                d={smoothPath(BROOK)}
                fill="none"
                stroke={INK.bank}
                strokeWidth={44}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d={smoothPath(BROOK)}
                fill="none"
                stroke={INK.water}
                strokeWidth={26}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d={smoothPath(BROOK)}
                fill="none"
                stroke={INK.waterEdge}
                strokeWidth={1.6}
                strokeDasharray="16 26"
                opacity={0.55}
                className="map-shimmer"
            />
        </g>
    );
}

function Wildwood() {
    const clearing = hub('wildwood');
    const woods = scatter(SHAPES.wildwood, 110, 'trees:wildwood', 4).filter(
        (p) => !near(p, clearing, 40),
    );

    return (
        <>
            <circle
                cx={clearing[0]}
                cy={clearing[1]}
                r={36}
                fill={INK.meadow}
            />
            <Trees list={trees(woods, 'wildwood', [9, 9])} />
            <path
                d={[0, 1, 2]
                    .map(
                        (i) =>
                            `M${clearing[0] + 8} ${clearing[1] - 20 + i * 4.5}h16`,
                    )
                    .join('')}
                stroke="#4f3d2c"
                strokeWidth={3.6}
                strokeLinecap="round"
            />
        </>
    );
}

function Orchard() {
    const s = SHAPES.orchard;
    const path = hub('orchard');
    const grove: TreeSpec[] = [];

    for (let row = 0; row < 12; row++) {
        for (let col = 0; col < 12; col++) {
            const point: Point = [
                s.box.x + 12 + col * 26 + (row % 2) * 13,
                s.box.y + 14 + row * 24,
            ];

            if (
                Math.abs(point[1] - path[1]) > 15 &&
                !(Math.abs(point[0] - path[0]) < 14 && point[1] > path[1]) &&
                fits(point, s.polygon, 12)
            ) {
                grove.push({
                    x: point[0],
                    y: point[1],
                    r: 8,
                    tone: 1 + (grove.length % 2),
                });
            }
        }
    }

    const blossom = grove.flatMap((tree, i) =>
        [0, 1, 2].map((j) => ({
            x: tree.x + (seeded(`bl:${i}:${j}:x`) * 2 - 1) * 5,
            y: tree.y + (seeded(`bl:${i}:${j}:y`) * 2 - 1) * 5,
            r: 1.3,
        })),
    );

    return (
        <>
            <path
                d={discs(
                    grove.map((t) => ({ x: t.x + 2, y: t.y + 2.6, r: t.r })),
                )}
                fill={INK.shadow}
                opacity={0.55}
            />
            <path
                d={discs(grove.filter((t) => t.tone === 1))}
                fill={INK.tree[1]}
            />
            <path
                d={discs(grove.filter((t) => t.tone === 2))}
                fill={INK.tree[2]}
            />
            <path d={discs(blossom)} fill="#d18cff" opacity={0.7} />
        </>
    );
}

function BigBarn() {
    const door = hub('big_barn');
    const straw: Disc[] = (
        [
            [-92, 6, 9],
            [-78, 24, 7],
            [84, 22, 8],
            [96, 6, 6],
        ] as const
    ).map(([dx, dy, r]) => {
        const [x, y] = at('big_barn', dx, dy);

        return { x, y, r };
    });

    return (
        <>
            <Bales list={straw} />
            <rect
                x={door[0] - 24}
                y={door[1] - 30}
                width={48}
                height={18}
                rx={2}
                fill={INK.yardStone}
            />
        </>
    );
}

function Hayfield() {
    const lane = hub('hayfield');
    const bales = scatter(SHAPES.hayfield, 16, 'bales', 14)
        .filter(([, y]) => Math.abs(y - lane[1]) > 18)
        .map(([x, y]) => ({ x, y, r: 6.5 }));

    return <Bales list={bales} />;
}

function Quarry() {
    const pit = at('quarry', 24, -22);
    const rocks = scatter(SHAPES.quarry, 22, 'rocks', 10).filter(
        (p) => !near(p, pit, 82),
    );

    return (
        <>
            <path
                d={smoothLoop(blob(pit, 80, 56, 'pit:0'))}
                fill="#2c2b33"
                stroke="#3d3b46"
                strokeWidth={1.5}
            />
            <path
                d={smoothLoop(blob(pit, 60, 40, 'pit:1'))}
                fill="#232229"
                stroke="#34323c"
                strokeWidth={1.2}
            />
            <path
                d={smoothLoop(blob(pit, 40, 26, 'pit:2'))}
                fill="#1b1a20"
                stroke="#2c2a33"
                strokeWidth={1}
            />
            <path
                d={smoothLoop(blob(pit, 20, 12, 'pit:3'))}
                fill={INK.water}
                opacity={0.9}
            />
            <path
                d={smoothPath([
                    hub('quarry'),
                    at('quarry', -10, 10),
                    at('quarry', 6, -6),
                ])}
                fill="none"
                stroke="#47434f"
                strokeWidth={3}
                strokeDasharray="5 4"
            />
            <path
                d={rocks
                    .map(([x, y], i) =>
                        smoothLoop(
                            blob(
                                [x, y],
                                6 + seeded(`rock:${i}`) * 6,
                                4 + seeded(`rock:h:${i}`) * 4,
                                `rock:${i}`,
                                0.3,
                                7,
                            ),
                        ),
                    )
                    .join('')}
                fill={INK.rockLight}
                stroke="#423f4b"
                strokeWidth={0.8}
            />
        </>
    );
}

function Sheepfold() {
    const s = SHAPES.sheepfold;
    const pens: { centre: Point; w: number; h: number; angle: number }[] = [
        { centre: at('sheepfold', -38, -34), w: 88, h: 52, angle: 6 },
        { centre: at('sheepfold', 52, -52), w: 58, h: 40, angle: -8 },
    ];
    const flock = [
        ...scatter(
            {
                ...s,
                box: {
                    x: pens[0].centre[0] - 36,
                    y: pens[0].centre[1] - 18,
                    width: 72,
                    height: 36,
                },
            },
            9,
            'flock:0',
            4,
        ),
        ...scatter(
            {
                ...s,
                box: {
                    x: pens[1].centre[0] - 22,
                    y: pens[1].centre[1] - 13,
                    width: 44,
                    height: 26,
                },
            },
            5,
            'flock:1',
            4,
        ),
        ...scatter(s, 7, 'flock:2', 20).filter(
            (p) => !near(p, hub('sheepfold'), 30),
        ),
    ];
    const heads = flock.map(([x, y], i) => {
        const angle = seeded(`sheep:${i}`) * Math.PI * 2;

        return {
            x: x + Math.cos(angle) * 4.4,
            y: y + Math.sin(angle) * 3.4,
            r: 1.7,
        };
    });

    return (
        <>
            {pens.map(({ centre: [x, y], w, h, angle }, i) => (
                <rect
                    key={i}
                    x={-w / 2}
                    y={-h / 2}
                    width={w}
                    height={h}
                    rx={8}
                    transform={`translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${angle})`}
                    fill="#1f2e28"
                    stroke={INK.stone}
                    strokeWidth={3.2}
                    strokeDasharray="2.4 2.2"
                />
            ))}
            <path
                d={discs(flock.map(([x, y]) => ({ x, y, r: 3.8 })))}
                fill={INK.wool}
            />
            <path d={discs(heads)} fill="#3a3546" />
            <Trees
                list={trees(
                    scatter(s, 4, 'sheepfold:trees', 22),
                    'sheepfold',
                    [8, 0],
                )}
            />
        </>
    );
}

function Granary() {
    return (
        <path
            d={smoothLoop(
                blob(at('granary', -6, -32), 104, 40, 'granary:yard', 0.1),
            )}
            fill="url(#cobbles)"
        />
    );
}

function Farmhouse() {
    const garden = at('farmhouse', 0, -16);
    const gardenTrees = (
        [
            [-84, -48],
            [-90, 8],
            [78, -58],
            [92, 2],
            [-58, 40],
        ] as const
    ).map(([dx, dy], i) => {
        const [x, y] = at('farmhouse', dx, dy);

        return { x, y, r: 10, tone: i % 3 };
    });

    return (
        <>
            <path
                d={smoothLoop(blob(garden, 118, 72, 'farmhouse:garden', 0.07))}
                fill="#1d2c23"
                stroke="#173022"
                strokeWidth={6}
            />
            <path
                d={smoothPath([
                    at('farmhouse', 2, 12),
                    at('farmhouse', 0, 36),
                    hub('farmhouse'),
                ])}
                fill="none"
                stroke="#3a3745"
                strokeWidth={11}
                strokeLinecap="round"
            />
            <Trees list={gardenTrees} />
        </>
    );
}

function WindmillHill() {
    const top = at('windmill_hill', 16, -30);

    return (
        <>
            {(
                [
                    [108, 74],
                    [80, 54],
                    [52, 36],
                ] as const
            ).map(([rx, ry], i) => (
                <path
                    key={i}
                    d={smoothLoop(blob(top, rx, ry, `hill:${i}`, 0.09))}
                    fill={i === 2 ? '#22322a' : 'none'}
                    stroke="#3a5142"
                    strokeWidth={1.5}
                    opacity={0.9}
                />
            ))}
        </>
    );
}

function DuckPond() {
    const s = SHAPES.duck_pond;
    const pond = at('duck_pond', -16, -18);
    const edge = blob(pond, 78, 48, 'pond', 0.14);
    const reeds = Array.from({ length: 34 }, (_, i) => {
        const angle = seeded(`reed:${i}`) * Math.PI * 2;
        const grow = 1.04 + seeded(`reed:r:${i}`) * 0.12;

        return [
            pond[0] + Math.cos(angle) * 78 * grow,
            pond[1] + Math.sin(angle) * 48 * grow,
        ] as Point;
    });
    const ducks = scatter(
        {
            ...s,
            polygon: edge,
            box: { x: pond[0] - 60, y: pond[1] - 34, width: 120, height: 68 },
        },
        6,
        'ducks',
        6,
    );

    return (
        <>
            <path
                d={smoothLoop(edge)}
                fill={INK.bank}
                transform="translate(0 2)"
            />
            <path
                d={smoothLoop(edge)}
                fill={INK.water}
                stroke={INK.waterEdge}
                strokeWidth={1.4}
                strokeOpacity={0.6}
            />
            <path
                d={smoothLoop(blob(pond, 52, 30, 'pond:ripple', 0.12))}
                fill="none"
                stroke={INK.waterEdge}
                strokeWidth={1}
                strokeDasharray="12 16"
                opacity={0.4}
                className="map-shimmer"
            />
            <path
                d={reeds
                    .map(
                        ([x, y], i) =>
                            `M${x.toFixed(1)} ${y.toFixed(1)}l${((seeded(`reed:x:${i}`) - 0.5) * 3).toFixed(1)} -6`,
                    )
                    .join('')}
                stroke="#3c6b48"
                strokeWidth={1.6}
                strokeLinecap="round"
            />
            <path
                d={discs(ducks.map(([x, y]) => ({ x, y, r: 2.8 })))}
                fill={INK.wool}
            />
            <path
                d={discs(
                    ducks.map(([x, y]) => ({ x: x + 3, y: y - 1, r: 1.4 })),
                )}
                fill="#2f7d4f"
            />
            <rect
                x={pond[0] + 58}
                y={pond[1] + 18}
                width={26}
                height={6}
                rx={1}
                fill="#4f3d2c"
                transform={`rotate(24 ${pond[0] + 58} ${pond[1] + 18})`}
            />
            <path
                d={[at('duck_pond', -64, 58), at('duck_pond', -20, 76)]
                    .map((point, i) =>
                        smoothLoop(
                            blob(point, 18, 11, `clay:pond:${i}`, 0.25, 9),
                        ),
                    )
                    .join('')}
                fill={INK.clay}
            />
        </>
    );
}

function Henhouse() {
    const s = SHAPES.henhouse;
    const [rx, ry] = at('henhouse', -70, -8);
    const hens = scatter(
        { ...s, box: { x: rx + 4, y: ry + 4, width: 120, height: 32 } },
        18,
        'hens',
        1,
    );

    return (
        <>
            <rect
                x={rx}
                y={ry}
                width={128}
                height={40}
                rx={4}
                fill="#2a2731"
                stroke={INK.stone}
                strokeWidth={1.4}
                strokeDasharray="3 3"
            />
            <path
                d={discs(hens.map(([x, y]) => ({ x, y, r: 2.4 })))}
                fill={INK.hen}
            />
        </>
    );
}

function MarketSquare() {
    const [x, y] = hub('market_square');

    return (
        <>
            <rect
                x={x - 66}
                y={y - 46}
                width={132}
                height={92}
                rx={12}
                fill="#2b2935"
                stroke="#3e3a4a"
                strokeWidth={1.6}
            />
            <rect
                x={x - 66}
                y={y - 46}
                width={132}
                height={92}
                rx={12}
                fill="url(#cobbles)"
                opacity={0.55}
            />
        </>
    );
}

function Stables() {
    const ring = at('stables', -56, 34);
    const hay = (
        [
            [72, -6],
            [84, 8],
            [60, 12],
        ] as const
    ).map(([dx, dy]) => {
        const [x, y] = at('stables', dx, dy);

        return { x, y, r: 6.5 };
    });

    return (
        <>
            <ellipse
                cx={ring[0]}
                cy={ring[1]}
                rx={42}
                ry={27}
                fill={INK.sand}
                stroke="#7a7388"
                strokeWidth={2}
                strokeDasharray="4 3"
            />
            <Bales list={hay} />
        </>
    );
}

function OldWell() {
    const bushes = scatter(SHAPES.old_well, 22, 'bushes', 10).filter(
        (p) => !near(p, hub('old_well'), 26),
    );

    return (
        <>
            {INK.tree.map((colour, tone) => (
                <path
                    key={colour}
                    d={bushes
                        .filter((_, i) => i % 3 === tone)
                        .map(([x, y], i) =>
                            smoothLoop(
                                blob(
                                    [x, y],
                                    6 + seeded(`bush:${tone}:${i}`) * 4,
                                    5 + seeded(`bush:h:${tone}:${i}`) * 3,
                                    `bush:${tone}:${i}`,
                                    0.25,
                                    8,
                                ),
                            ),
                        )
                        .join('')}
                    fill={colour}
                />
            ))}
            {(
                [
                    [-62, 44, 26, 15],
                    [52, 56, 20, 12],
                    [70, -40, 16, 10],
                ] as const
            ).map(([dx, dy, rx, ry], i) => (
                <path
                    key={i}
                    d={smoothLoop(
                        blob(
                            at('old_well', dx, dy),
                            rx,
                            ry,
                            `clay:well:${i}`,
                            0.25,
                            9,
                        ),
                    )}
                    fill={i === 1 ? '#574535' : INK.clay}
                />
            ))}
        </>
    );
}

function Paddock() {
    const exits = [
        'duck_pond',
        'henhouse',
        'market_square',
        'stables',
        'old_well',
    ].map((key) => hub(key)[0]);
    const shade = scatter(SHAPES.paddock, 14, 'paddock:trees', 16).filter(
        ([x]) => exits.every((exit) => Math.abs(x - exit) > 34),
    );
    const [x, y] = at('paddock', -150, -8);

    return (
        <>
            <Trees list={trees(shade, 'paddock', [9, 4])} />
            <rect
                x={x}
                y={y}
                width={34}
                height={9}
                rx={2}
                fill={INK.water}
                stroke={INK.stone}
                strokeWidth={1.6}
            />
        </>
    );
}

/** Ground details, drawn under the lanes. */
/** A farm beyond the fences: its fields, a few trees and a duck pond or two. */
function farmland(key: string) {
    return function Farmland() {
        const s = SHAPES[key];
        const [cx, cy] = s.centre;

        return (
            <>
                <path
                    d={smoothLoop(
                        blob(
                            [cx - 40, cy + 40],
                            120,
                            70,
                            `${key}:meadow`,
                            0.12,
                        ),
                    )}
                    fill="url(#meadow)"
                />
                <Trees
                    list={trees(
                        scatter(s, 14, `${key}:trees`, 26),
                        key,
                        [8, 6],
                    )}
                />
                <path
                    d={smoothLoop(
                        blob(
                            [cx + 110, cy + 60],
                            26,
                            16,
                            `${key}:pond`,
                            0.2,
                            10,
                        ),
                    )}
                    fill={INK.water}
                    stroke={INK.waterEdge}
                    strokeOpacity={0.5}
                />
                <Crop crop={FARM_CROP[key] ?? 'iron'} centre={[cx, cy]} />
            </>
        );
    };
}

/** What each farm grows, as ValleyBuilder has it. */
const FARM_CROP: Record<string, 'honey' | 'herbs' | 'iron'> = {
    thornbury: 'honey',
    brookside: 'herbs',
    willowby: 'iron',
    ashcombe: 'iron',
    fennick: 'honey',
    marlow: 'herbs',
};

/** What makes each farm worth having: hives, herb beds or an old forge's ore. */
function Crop({
    crop,
    centre: [cx, cy],
}: {
    crop: 'honey' | 'herbs' | 'iron';
    centre: Point;
}) {
    if (crop === 'honey') {
        const hives = Array.from({ length: 8 }, (_, i) => ({
            x: cx + 40 + (i % 4) * 22,
            y: cy - 40 + Math.floor(i / 4) * 22,
            r: 6,
        }));

        return (
            <>
                <path
                    d={discs(
                        hives.map((h) => ({ ...h, x: h.x + 1.5, y: h.y + 2 })),
                    )}
                    fill={INK.shadow}
                    opacity={0.6}
                />
                <path d={discs(hives)} fill="#e3b341" />
                <path
                    d={discs(hives.map((h) => ({ ...h, r: 2.5 })))}
                    fill="#6b4f12"
                />
            </>
        );
    }

    if (crop === 'herbs') {
        return (
            <path
                d={Array.from(
                    { length: 6 },
                    (_, i) => `M${cx + 30} ${cy - 70 + i * 14}h90`,
                ).join('')}
                stroke="#3fae7a"
                strokeWidth={6}
                strokeLinecap="round"
                opacity={0.85}
            />
        );
    }

    const ore = Array.from(
        { length: 6 },
        (_, i) =>
            [cx + 50 + (i % 3) * 26, cy - 34 + Math.floor(i / 3) * 24] as Point,
    );

    return (
        <path
            d={ore
                .map((point, i) =>
                    smoothLoop(blob(point, 9, 6, `ore:${i}`, 0.3, 7)),
                )
                .join('')}
            fill="#6c6f7d"
            stroke="#9aa0b4"
            strokeWidth={0.8}
        />
    );
}

const UNDER: Record<string, () => React.ReactNode> = {
    thornbury: farmland('thornbury'),
    brookside: farmland('brookside'),
    willowby: farmland('willowby'),
    ashcombe: farmland('ashcombe'),
    fennick: farmland('fennick'),
    marlow: farmland('marlow'),
    wildwood: Wildwood,
    orchard: Orchard,
    big_barn: BigBarn,
    hayfield: Hayfield,
    quarry: Quarry,
    sheepfold: Sheepfold,
    granary: Granary,
    farmhouse: Farmhouse,
    windmill_hill: WindmillHill,
    duck_pond: DuckPond,
    henhouse: Henhouse,
    market_square: MarketSquare,
    stables: Stables,
    old_well: OldWell,
    paddock: Paddock,
};

/** Trees in the hedges, one per bend in a border. */
function hedgeTrees(): TreeSpec[] {
    const seen = new Set<string>();
    const points = Object.entries(SHAPES)
        .filter(([key]) => !CITY[key])
        .map(([, shape]) => shape)
        .flatMap((shape) =>
            shape.polygon.filter((point, i) => {
                const key = `${point[0].toFixed(0)},${point[1].toFixed(0)}`;

                if (i % 3 === 0 || seen.has(key)) {
                    return false;
                }

                seen.add(key);

                return seeded(`hedge:${key}`) > 0.42;
            }),
        );

    return trees(points, 'hedge', [5, 3]);
}

/**
 * The farm's ground: the land around it, the fence, every region's soil and
 * what grows on it, and the hedges between regions.
 */
export const MapGround = memo(function MapGround() {
    // Irongate has its own walls and streets, drawn after the farm's fields and hedges.
    const shapes = Object.entries(SHAPES).filter(([key]) => !CITY[key]);
    const outlines = shapes.map(([, shape]) => toPath(shape.polygon)).join('');

    return (
        <g>
            <defs>
                <Patterns />
                {[
                    ...Object.entries(SHAPES),
                    ...Object.entries(FIELD_SHAPES),
                ].map(([key, shape]) => (
                    <clipPath key={key} id={`clip-${key}`}>
                        <path d={toPath(shape.polygon)} />
                    </clipPath>
                ))}
            </defs>
            <Surroundings />
            {/* The fence round the farm: the half of these strokes outside the fields. */}
            <path
                d={outlines}
                fill="none"
                stroke={INK.fence}
                strokeWidth={12}
                strokeLinejoin="round"
            />
            <path
                d={outlines}
                fill="none"
                stroke={INK.fencePost}
                strokeWidth={12}
                strokeDasharray="2 14"
            />
            {shapes.map(([key, shape]) => {
                const Under = UNDER[key];

                return (
                    <g key={key} clipPath={`url(#clip-${key})`}>
                        <path
                            d={toPath(shape.polygon)}
                            fill={GROUND[key] ?? INK.meadow}
                        />
                        {Under && <Under />}
                    </g>
                );
            })}
            <path
                d={outlines}
                fill="none"
                stroke={INK.hedge}
                strokeWidth={6}
                strokeLinejoin="round"
                opacity={0.85}
            />
            <Trees list={hedgeTrees()} />
            <path
                d={outlines}
                fill="none"
                stroke={INK.border}
                strokeWidth={1.4}
                strokeDasharray="7 6"
                opacity={0.75}
            />
            <Irongate />
        </g>
    );
});

/** Nothing is painted over the land: animals go anywhere, and factions raise their own buildings. */
export const MapFeatures = memo(function MapFeatures() {
    return <g></g>;
});

/**
 * A patch of wild land that its farm has grown into: cleared, fenced and
 * planted with the farm's crop. Drawn over the scrub it replaced.
 */
export function FieldGround({ fieldKey }: { fieldKey: string }) {
    const field = FIELDS[fieldKey];
    const shape = FIELD_SHAPES[fieldKey];

    if (!field || !shape) {
        return null;
    }

    const d = toPath(shape.polygon);

    return (
        <g>
            <path
                d={d}
                fill="none"
                stroke={INK.fence}
                strokeWidth={12}
                strokeLinejoin="round"
            />
            <path d={d} fill={`url(#rows-${field.farm})`} />
            <g clipPath={`url(#clip-${fieldKey})`}>
                <Trees
                    list={trees(
                        scatter(shape, 5, `field:${fieldKey}`, 24),
                        fieldKey,
                        [7, 4],
                    )}
                />
            </g>
            <path
                d={d}
                fill="none"
                stroke={INK.hedge}
                strokeWidth={6}
                strokeLinejoin="round"
                opacity={0.85}
            />
            <path
                d={d}
                fill="none"
                stroke={INK.border}
                strokeWidth={1.4}
                strokeDasharray="7 6"
                opacity={0.75}
            />
        </g>
    );
}
