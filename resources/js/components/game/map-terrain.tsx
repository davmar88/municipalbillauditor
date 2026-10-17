import { memo } from 'react';
import type { Point } from '@/lib/hollowmere';
import {
    BROOK,
    LINKS,
    ROAD_LINKS,
    ROADS,
    SHAPES,
    WORLD,
    blob,
    fits,
    hub,
    pairKey,
    scatter,
    scatterBox,
    seeded,
    smoothLoop,
    smoothPath,
    toPath,
    walkPath,
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

const STALLS = ['#b39aff', '#41d1ff', '#d18cff', '#ff8fb1', '#ffd33d'];

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
};

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
        ],
        'edge',
        [8, 7],
    );

    return (
        <g>
            <rect width={WORLD.width} height={WORLD.height} fill={INK.beyond} />
            <rect
                x={1490}
                y={0}
                width={WORLD.width - 1490}
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
            {/* Ironfield's barns, beyond the east fence, with a lamp lit. */}
            <Building
                at={[1552, 296]}
                w={42}
                h={28}
                angle={4}
                roof="#2a2531"
                edge="#3d3646"
            />
            <Building
                at={[1546, 700]}
                w={52}
                h={30}
                angle={-3}
                roof="#2a2531"
                edge="#3d3646"
            />
            <path d="M1540 290h4v3h-4zM1556 696h4v3h-4z" fill="#dd6b30" />
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

function BigBarnBuildings() {
    const [x, y] = at('big_barn', 0, -26);

    return (
        <>
            <Building at={[x, y]} w={136} h={64} angle={-3} />
            {/* The Barn Wall: the gable end painted white, and the rules on a board. */}
            <g
                transform={`translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(-3)`}
            >
                <rect
                    x={62}
                    y={-30}
                    width={6}
                    height={60}
                    fill={INK.wall}
                    opacity={0.9}
                />
                <rect
                    x={82}
                    y={-18}
                    width={34}
                    height={26}
                    rx={2}
                    fill={INK.wall}
                />
                <path
                    d="M87 -13h20M87 -8h22M87 -3h18M87 2h21"
                    stroke="#3a3447"
                    strokeWidth={1.4}
                />
            </g>
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

function GranaryBuildings() {
    const silos: [Point, number][] = [
        [at('granary', -54, -30), 21],
        [at('granary', -14, -46), 15],
    ];

    return (
        <>
            <path
                d={discs(
                    silos.map(([[x, y], r]) => ({ x: x + 3, y: y + 4, r })),
                )}
                fill={INK.shadow}
                opacity={0.65}
            />
            <path
                d={discs(silos.map(([[x, y], r]) => ({ x, y, r })))}
                fill={INK.roofDark}
                stroke={INK.buildingEdge}
                strokeWidth={1.3}
            />
            <path
                d={discs(silos.map(([[x, y], r]) => ({ x, y, r: r * 0.62 })))}
                fill={INK.roof}
            />
            <path
                d={discs(silos.map(([[x, y]]) => ({ x, y, r: 2 })))}
                fill={INK.buildingEdge}
            />
            <Building at={at('granary', 44, -28)} w={78} h={34} angle={4} />
        </>
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

function FarmhouseBuildings() {
    const [cx, cy] = at('farmhouse', -40, -38);

    return (
        <>
            <Building at={at('farmhouse', -10, -20)} w={94} h={46} angle={-2} />
            <Building at={at('farmhouse', 34, 2)} w={36} h={42} angle={-2} />
            <rect
                x={cx}
                y={cy}
                width={7}
                height={7}
                fill="#4a4357"
                stroke={INK.buildingEdge}
            />
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

function Windmill() {
    const [x, y] = at('windmill_hill', 16, -30);

    return (
        <g transform={`translate(${x.toFixed(1)} ${y.toFixed(1)})`}>
            <circle cx={3} cy={4} r={14} fill={INK.shadow} opacity={0.65} />
            <circle
                r={14}
                fill={INK.roofDark}
                stroke={INK.buildingEdge}
                strokeWidth={1.4}
            />
            <circle r={8} fill={INK.roof} />
            <g className="map-sails">
                <path
                    d="M-3.5 -46h7v40h-7zM-3.5 6h7v40h-7zM6 -3.5h40v7H6zM-46 -3.5h40v7h-40z"
                    fill="#cfc8dd"
                    opacity={0.85}
                />
                <path
                    d="M0 -46v40M0 6v40M6 0h40M-46 0h40M-3.5 -38h7M-3.5 -30h7M-3.5 -22h7M-3.5 -14h7M-3.5 14h7M-3.5 22h7M-3.5 30h7M-3.5 38h7M14 -3.5v7M22 -3.5v7M30 -3.5v7M38 -3.5v7M-14 -3.5v7M-22 -3.5v7M-30 -3.5v7M-38 -3.5v7"
                    stroke="#6f6881"
                    strokeWidth={0.9}
                />
                <circle r={3.5} fill="#4f4860" />
            </g>
        </g>
    );
}

function IronfieldGateBuildings() {
    return (
        <>
            <Building
                at={at('ironfield_gate', 44, -62)}
                w={28}
                h={22}
                angle={6}
            />
            {/* The gate in the east fence, where Gate Road leaves the farm. */}
            <g transform="translate(1484 478)">
                <path
                    d="M-5 -24h10v10h-10zM-5 14h10v10h-10z"
                    fill="#5a5368"
                    stroke="#8a8399"
                />
                <path
                    d="M0 -14L-20 -4M0 14L-20 4"
                    stroke="#a59db6"
                    strokeWidth={3}
                    strokeLinecap="round"
                />
            </g>
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

function HenhouseBuildings() {
    return (
        <>
            {(
                [
                    [-52, -44, -4],
                    [-12, -52, 3],
                    [30, -44, -6],
                ] as const
            ).map(([dx, dy, angle], i) => (
                <Building
                    key={i}
                    at={at('henhouse', dx, dy)}
                    w={30}
                    h={20}
                    angle={angle}
                />
            ))}
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

function MarketBuildings() {
    const [x, y] = hub('market_square');
    const stalls: Point[] = [
        [-48, -30],
        [-22, -32],
        [22, -32],
        [48, -30],
        [-50, 30],
        [-24, 32],
        [24, 32],
        [50, 30],
    ];

    return (
        <>
            {STALLS.map((colour, c) => (
                <path
                    key={colour}
                    d={stalls
                        .filter((_, i) => i % STALLS.length === c)
                        .map(
                            ([dx, dy]) =>
                                `M${x + dx - 8} ${y + dy - 6}h16v12h-16z`,
                        )
                        .join('')}
                    fill={colour}
                    opacity={0.85}
                />
            ))}
            <path
                d={stalls
                    .map(
                        ([dx, dy]) =>
                            `M${x + dx - 3} ${y + dy - 6}v12M${x + dx + 3} ${y + dy - 6}v12`,
                    )
                    .join('')}
                stroke="#14121a"
                strokeWidth={1.2}
                opacity={0.5}
            />
            <circle
                cx={x}
                cy={y}
                r={10}
                fill={INK.water}
                stroke={INK.stone}
                strokeWidth={2.4}
            />
            {(
                [
                    [-98, -48, 36, 28, 3],
                    [-100, 50, 32, 30, -4],
                    [100, -50, 34, 26, -3],
                    [98, 52, 36, 28, 4],
                    [-50, -84, 40, 22, 2],
                    [54, -82, 38, 22, -2],
                ] as const
            ).map(([dx, dy, w, h, angle], i) => (
                <Building
                    key={i}
                    at={[x + dx, y + dy]}
                    w={w}
                    h={h}
                    angle={angle}
                />
            ))}
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

function StablesBuildings() {
    const [x, y] = at('stables', -6, -46);

    return (
        <>
            <Building at={[x, y]} w={130} h={32} angle={2} />
            <g
                transform={`translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(2)`}
            >
                <path
                    d="M-48 -16v32M-32 -16v32M-16 -16v32M0 -16v32M16 -16v32M32 -16v32M48 -16v32"
                    stroke={INK.buildingEdge}
                    strokeWidth={0.9}
                />
            </g>
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

function OldWellBuildings() {
    const [x, y] = at('old_well', 14, -28);

    return (
        <g transform={`translate(${x.toFixed(1)} ${y.toFixed(1)})`}>
            <circle cx={2} cy={3} r={14} fill={INK.shadow} opacity={0.6} />
            <circle
                r={13}
                fill="#0f1d2e"
                stroke={INK.stone}
                strokeWidth={4.5}
            />
            <rect x={-17} y={-2} width={34} height={4} rx={1} fill="#4f3d2c" />
            <path
                d={discs([
                    { x: -17, y: 0, r: 2.6 },
                    { x: 17, y: 0, r: 2.6 },
                ])}
                fill="#5d4a36"
            />
        </g>
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
const UNDER: Record<string, () => React.ReactNode> = {
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

/** Buildings, drawn over the lanes. */
const OVER: Record<string, () => React.ReactNode> = {
    big_barn: BigBarnBuildings,
    granary: GranaryBuildings,
    farmhouse: FarmhouseBuildings,
    windmill_hill: Windmill,
    ironfield_gate: IronfieldGateBuildings,
    henhouse: HenhouseBuildings,
    market_square: MarketBuildings,
    stables: StablesBuildings,
    old_well: OldWellBuildings,
};

/** Trees in the hedges, one per bend in a border. */
function hedgeTrees(): TreeSpec[] {
    const seen = new Set<string>();
    const points = Object.values(SHAPES).flatMap((shape) =>
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
    const shapes = Object.entries(SHAPES);
    const outlines = shapes.map(([, shape]) => toPath(shape.polygon)).join('');

    return (
        <g>
            <defs>
                <Patterns />
                {shapes.map(([key, shape]) => (
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
        </g>
    );
});

/** The lanes, footpaths, the bridge and the buildings. */
export const MapFeatures = memo(function MapFeatures() {
    const footpaths = LINKS.filter(([a, b]) => !ROAD_LINKS.has(pairKey(a, b)))
        .map(([a, b]) => smoothPath(walkPath(a, b)))
        .join('');
    const lanes = ROADS.filter((road) => !road.major)
        .map((road) => smoothPath(road.points))
        .join('');
    const majors = ROADS.filter((road) => road.major)
        .map((road) => smoothPath(road.points))
        .join('');

    return (
        <g>
            <path
                d={footpaths}
                fill="none"
                stroke={INK.roadCasing}
                strokeWidth={5}
                strokeLinecap="round"
                opacity={0.6}
            />
            <path
                d={footpaths}
                fill="none"
                stroke={INK.path}
                strokeWidth={2.4}
                strokeDasharray="1 6"
                strokeLinecap="round"
            />
            <path
                d={lanes}
                fill="none"
                stroke={INK.roadCasing}
                strokeWidth={14}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d={majors}
                fill="none"
                stroke={INK.roadCasing}
                strokeWidth={19}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d={lanes}
                fill="none"
                stroke={INK.road}
                strokeWidth={9}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d={majors}
                fill="none"
                stroke={INK.roadMajor}
                strokeWidth={13}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d={majors}
                fill="none"
                stroke={INK.roadLine}
                strokeWidth={1.1}
                strokeDasharray="10 12"
            />
            {/* The bridge where Harrow Lane crosses Hollow Brook. */}
            <rect
                x={777}
                y={996}
                width={38}
                height={46}
                rx={3}
                fill={INK.roadMajor}
                stroke={INK.roadCasing}
                strokeWidth={2}
            />
            <path
                d="M779 994v50M813 994v50"
                stroke="#8a8399"
                strokeWidth={2.4}
            />
            {Object.entries(OVER).map(([key, Over]) => (
                <g key={key}>
                    <Over />
                </g>
            ))}
        </g>
    );
});
