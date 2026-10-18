/**
 * The shape of Hollowmere. The rules see a 5 by 3 grid of regions with the
 * Paddock along the bottom; the map bends that grid into fields, woods and
 * yards, so regions that border each other on the map are exactly the ones
 * that border each other in the rules. Everything here is worked out from
 * fixed seeds, so the map looks the same for every player.
 */

export type Point = [number, number];

/** Everything drawn: Hollowmere, the farms beyond its fences and the land round them. */
export const WORLD = { x: -460, y: -400, width: 2200, height: 1880 };

/** Hollowmere and its farms, for seeing everything at once. */
export const REACH = { x: -380, y: -300, width: 2020, height: 1700 };

/** The farm without the land around it, for fitting it on screen. */
export const FARM = { x: 96, y: 92, width: 1408, height: 904 };

const XS = [120, 392, 664, 936, 1208, 1480];
const YS = [120, 362, 604, 846];
const PADDOCK_BOTTOM = 972;

/** Where each region sits on the rules' grid, as ValleyBuilder lays it out. */
export const LAYOUT: Record<string, [number, number]> = {
    wildwood: [0, 0],
    orchard: [1, 0],
    big_barn: [2, 0],
    hayfield: [3, 0],
    quarry: [4, 0],
    sheepfold: [0, 1],
    granary: [1, 1],
    farmhouse: [2, 1],
    windmill_hill: [3, 1],
    ironfield_gate: [4, 1],
    duck_pond: [0, 2],
    henhouse: [1, 2],
    market_square: [2, 2],
    stables: [3, 2],
    old_well: [4, 2],
    paddock: [2, 3],
};

/** A repeatable random number in [0, 1) from any key. */
export function seeded(key: string): number {
    let hash = 2166136261;

    for (let i = 0; i < key.length; i++) {
        hash ^= key.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }

    hash ^= hash >>> 13;
    hash = Math.imul(hash, 0x5bd1e995);
    hash ^= hash >>> 15;

    return (hash >>> 0) / 4294967296;
}

function jitter(key: string, amount: number): number {
    return (seeded(key) * 2 - 1) * amount;
}

/** The corners of the grid, nudged so the fields don't look ruled. */
function corner(col: number, row: number): Point {
    const outerX = col === 0 || col === XS.length - 1;
    const outerY = row === 0 || row === YS.length - 1;
    const key = `corner:${col}:${row}`;

    return [
        XS[col] + jitter(`${key}:x`, outerX ? 12 : 38),
        YS[row] + jitter(`${key}:y`, outerY ? 12 : 32),
    ];
}

/** Two bends along a border, the same for the regions on both sides of it. */
function bends(a: Point, b: Point, key: string): Point[] {
    const dx = b[0] - a[0];
    const dy = b[1] - a[1];
    const length = Math.hypot(dx, dy) || 1;
    const nx = -dy / length;
    const ny = dx / length;

    return [1 / 3, 2 / 3].map((t, i) => {
        const offset = jitter(`${key}:${i}`, 16);

        return [
            a[0] + dx * t + nx * offset,
            a[1] + dy * t + ny * offset,
        ] as Point;
    });
}

function edge(a: Point, b: Point, keyA: string, keyB: string): Point[] {
    // Sort the ends so both neighbours bend the border the same way.
    if (keyA < keyB) {
        return bends(a, b, `${keyA}|${keyB}`);
    }

    return bends(b, a, `${keyB}|${keyA}`).reverse();
}

export type RegionShape = {
    polygon: Point[];
    centre: Point;
    box: { x: number; y: number; width: number; height: number };
};

function shape(polygon: Point[]): RegionShape {
    const xs = polygon.map((p) => p[0]);
    const ys = polygon.map((p) => p[1]);
    const x = Math.min(...xs);
    const y = Math.min(...ys);

    return {
        polygon,
        centre: [
            xs.reduce((a, b) => a + b, 0) / xs.length,
            ys.reduce((a, b) => a + b, 0) / ys.length,
        ],
        box: { x, y, width: Math.max(...xs) - x, height: Math.max(...ys) - y },
    };
}

/** The outline of the region at a grid cell, or of the Paddock. */
export function regionShape(
    col: number,
    row: number,
    safe: boolean,
): RegionShape {
    if (safe) {
        const top: Point[] = [];

        for (let c = 0; c < XS.length - 1; c++) {
            const a = corner(c, 3);
            const b = corner(c + 1, 3);
            top.push(a, ...edge(a, b, `${c}:3`, `${c + 1}:3`));
        }

        top.push(corner(XS.length - 1, 3));

        const bottom: Point[] = [];

        for (let i = 10; i >= 0; i--) {
            const x = XS[0] + ((XS[XS.length - 1] - XS[0]) * i) / 10;
            const sway = i === 0 || i === 10 ? 0 : jitter(`paddock:${i}`, 10);
            bottom.push([x, PADDOCK_BOTTOM + sway]);
        }

        return shape([...top, ...bottom]);
    }

    const corners: [Point, string][] = [
        [corner(col, row), `${col}:${row}`],
        [corner(col + 1, row), `${col + 1}:${row}`],
        [corner(col + 1, row + 1), `${col + 1}:${row + 1}`],
        [corner(col, row + 1), `${col}:${row + 1}`],
    ];
    const polygon: Point[] = [];

    corners.forEach(([point, key], i) => {
        const [next, nextKey] = corners[(i + 1) % 4];
        polygon.push(point, ...edge(point, next, key, nextKey));
    });

    return shape(polygon);
}

/** The farms beyond the fences, and the Hollowmere regions each borders, as ValleyBuilder has them. */
export const FARMS: Record<string, { polygon: Point[]; borders: string[] }> = {
    thornbury: {
        polygon: [
            [392, 96],
            [560, 86],
            [800, 98],
            [1040, 88],
            [1212, 96],
            [1250, -40],
            [1190, -230],
            [800, -268],
            [410, -232],
            [352, -60],
        ],
        borders: ['orchard', 'big_barn', 'hayfield'],
    },
    brookside: {
        polygon: [
            [22, 112],
            [32, 330],
            [12, 560],
            [26, 846],
            [-150, 890],
            [-330, 770],
            [-366, 480],
            [-330, 200],
            [-160, 88],
        ],
        borders: ['wildwood', 'sheepfold', 'duck_pond'],
    },
    willowby: {
        polygon: [
            [430, 1074],
            [620, 1062],
            [800, 1080],
            [980, 1064],
            [1170, 1078],
            [1204, 1220],
            [1124, 1342],
            [800, 1374],
            [480, 1338],
            [398, 1212],
        ],
        borders: ['paddock'],
    },
};

/** Which regions border which, by the same rule as ValleyBuilder. */
export const LINKS: [string, string][] = [
    ...Object.entries(FARMS).flatMap(([farm, { borders }]) =>
        borders.map((key): [string, string] => [key, farm]),
    ),
    ...Object.keys(LAYOUT).flatMap((a, i, keys) =>
        keys.slice(i + 1).flatMap((b): [string, string][] => {
            const [ac, ar] = LAYOUT[a];
            const [bc, br] = LAYOUT[b];
            const touches =
                a === 'paddock' || b === 'paddock'
                    ? (a === 'paddock' ? br : ar) === 2
                    : Math.abs(ac - bc) + Math.abs(ar - br) === 1;

            return touches ? [[a, b]] : [];
        }),
    ),
];

/** A wobbly ring of points around an ellipse, for ponds, pits and hills. */
export function blob(
    centre: Point,
    rx: number,
    ry: number,
    key: string,
    wobble = 0.16,
    count = 16,
): Point[] {
    return Array.from({ length: count }, (_, i) => {
        const angle = (i / count) * Math.PI * 2;
        const scale = 1 + (seeded(`${key}:${i}`) * 2 - 1) * wobble;

        return [
            centre[0] + Math.cos(angle) * rx * scale,
            centre[1] + Math.sin(angle) * ry * scale,
        ] as Point;
    });
}

/** A closed smooth outline through points, for ponds and hills. */
export function smoothLoop(points: Point[]): string {
    const n = points.length;
    let d = `M${points[0][0].toFixed(1)} ${points[0][1].toFixed(1)}`;

    for (let i = 0; i < n; i++) {
        const p0 = points[(i - 1 + n) % n];
        const p1 = points[i];
        const p2 = points[(i + 1) % n];
        const p3 = points[(i + 2) % n];
        d += `C${(p1[0] + (p2[0] - p0[0]) / 6).toFixed(1)} ${(p1[1] + (p2[1] - p0[1]) / 6).toFixed(1)} ${(p2[0] - (p3[0] - p1[0]) / 6).toFixed(1)} ${(p2[1] - (p3[1] - p1[1]) / 6).toFixed(1)} ${p2[0].toFixed(1)} ${p2[1].toFixed(1)}`;
    }

    return `${d}Z`;
}

/** Every region's outline, worked out once. */
export const SHAPES: Record<string, RegionShape> = Object.fromEntries([
    ...Object.entries(LAYOUT).map(([key, [col, row]]) => [
        key,
        regionShape(col, row, key === 'paddock'),
    ]),
    ...Object.entries(FARMS).map(([key, farm]) => [key, shape(farm.polygon)]),
]);

export function shapeOf(
    key: string,
    col: number,
    row: number,
    safe: boolean,
): RegionShape {
    return SHAPES[key] ?? regionShape(col, row, safe);
}

export function toPath(points: Point[]): string {
    return `M${points.map((p) => `${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('L')}Z`;
}

export function inside(point: Point, polygon: Point[]): boolean {
    let hit = false;

    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const [xi, yi] = polygon[i];
        const [xj, yj] = polygon[j];

        if (
            yi > point[1] !== yj > point[1] &&
            point[0] < ((xj - xi) * (point[1] - yi)) / (yj - yi) + xi
        ) {
            hit = !hit;
        }
    }

    return hit;
}

/** Whether a point is inside a polygon with room to spare on every side. */
export function fits(point: Point, polygon: Point[], margin = 0): boolean {
    return (
        inside(point, polygon) &&
        [
            [margin, 0],
            [-margin, 0],
            [0, margin],
            [0, -margin],
        ].every(([dx, dy]) => inside([point[0] + dx, point[1] + dy], polygon))
    );
}

/** Repeatable points scattered inside a region, kept off its borders. */
export function scatter(
    region: RegionShape,
    count: number,
    key: string,
    margin = 18,
): Point[] {
    const points: Point[] = [];
    const { x, y, width, height } = region.box;

    for (let i = 0; points.length < count && i < count * 40; i++) {
        const candidate: Point = [
            x + seeded(`${key}:x:${i}`) * width,
            y + seeded(`${key}:y:${i}`) * height,
        ];

        if (fits(candidate, region.polygon, margin)) {
            points.push(candidate);
        }
    }

    return points;
}

/** Repeatable points scattered in a rectangle, for the land around the farm. */
export function scatterBox(
    box: { x: number; y: number; width: number; height: number },
    count: number,
    key: string,
): Point[] {
    return Array.from({ length: count }, (_, i) => [
        box.x + seeded(`${key}:x:${i}`) * box.width,
        box.y + seeded(`${key}:y:${i}`) * box.height,
    ]);
}

/** A smooth line through points, for lanes, paths and the brook. */
export function smoothPath(points: Point[]): string {
    if (points.length < 2) {
        return '';
    }

    let d = `M${points[0][0].toFixed(1)} ${points[0][1].toFixed(1)}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] ?? p2;
        const c1: Point = [
            p1[0] + (p2[0] - p0[0]) / 6,
            p1[1] + (p2[1] - p0[1]) / 6,
        ];
        const c2: Point = [
            p2[0] - (p3[0] - p1[0]) / 6,
            p2[1] - (p3[1] - p1[1]) / 6,
        ];
        d += `C${c1[0].toFixed(1)} ${c1[1].toFixed(1)} ${c2[0].toFixed(1)} ${c2[1].toFixed(1)} ${p2[0].toFixed(1)} ${p2[1].toFixed(1)}`;
    }

    return d;
}

/** Where the lanes meet in each region, as a nudge from its middle. */
const HUBS: Record<string, Point> = {
    wildwood: [34, 38],
    orchard: [0, 34],
    big_barn: [0, 54],
    hayfield: [-8, 40],
    quarry: [-40, 46],
    sheepfold: [38, 22],
    granary: [6, 44],
    farmhouse: [0, 60],
    windmill_hill: [-10, 56],
    ironfield_gate: [-24, 14],
    duck_pond: [38, 44],
    henhouse: [8, 34],
    market_square: [0, 8],
    stables: [18, 28],
    old_well: [-20, 18],
    paddock: [0, 6],
};

/** Where a faction's building goes in each region, as a nudge from its middle. */
const PLOTS: Record<string, Point> = {
    thornbury: [150, 40],
    brookside: [60, 150],
    willowby: [170, 30],
    wildwood: [-62, -8],
    orchard: [-72, -12],
    big_barn: [-96, 52],
    hayfield: [62, -36],
    quarry: [-84, -30],
    sheepfold: [-44, 52],
    granary: [72, 40],
    windmill_hill: [-86, 20],
    ironfield_gate: [54, 56],
    duck_pond: [76, -52],
    henhouse: [82, -14],
    market_square: [0, 80],
    stables: [64, 58],
    old_well: [-66, -34],
};

/** The spot where a faction builds in a region. */
export function plot(key: string): Point {
    const shape = SHAPES[key];
    const [dx, dy] = PLOTS[key] ?? [60, 40];

    return shape ? [shape.centre[0] + dx, shape.centre[1] + dy] : hub(key);
}

/** The spot in a region where its lanes meet and animals gather. */
export function hub(key: string): Point {
    const shape = SHAPES[key];

    if (!shape) {
        return [WORLD.width / 2, WORLD.height / 2];
    }

    const [dx, dy] = HUBS[key] ?? [0, 0];

    return [shape.centre[0] + dx, shape.centre[1] + dy];
}

/** Where a walk between two regions starts: the Paddock is long, so walks leave it straight below the other region. */
export function anchor(key: string, towards: string): Point {
    if (key === 'paddock' && towards !== 'paddock') {
        return [hub(towards)[0], hub('paddock')[1]];
    }

    return hub(key);
}

/** A gently bent line from one region to its neighbour. */
export function walkPath(from: string, to: string): Point[] {
    const a = anchor(from, to);
    const b = anchor(to, from);
    const sorted = from < to ? `${from}|${to}` : `${to}|${from}`;
    const bend = (seeded(`walk:${sorted}`) * 2 - 1) * 22 * (from < to ? 1 : -1);
    const dx = b[0] - a[0];
    const dy = b[1] - a[1];
    const length = Math.hypot(dx, dy) || 1;
    const mid: Point = [
        (a[0] + b[0]) / 2 + (-dy / length) * bend,
        (a[1] + b[1]) / 2 + (dx / length) * bend,
    ];

    return [a, mid, b];
}

export type Road = {
    name: string;
    /** The regions the lane runs through, in order. */
    keys: string[];
    points: Point[];
    major?: boolean;
    /** The two regions the name is written between. */
    labelBetween: [string, string];
};

/** A lane through regions' hubs, bending a little between each. */
function lane(
    keys: string[],
    before: Point[] = [],
    after: Point[] = [],
): Point[] {
    const points: Point[] = [...before];

    keys.forEach((key, i) => {
        if (i > 0) {
            points.push(walkPath(keys[i - 1], key)[1]);
        }

        points.push(
            i === 0 && key === 'paddock' && keys[1]
                ? anchor('paddock', keys[1])
                : hub(key),
        );
    });

    return [...points, ...after];
}

function road(
    name: string,
    keys: string[],
    labelBetween: [string, string],
    extra: { major?: boolean; before?: Point[]; after?: Point[] } = {},
): Road {
    return {
        name,
        keys,
        points: lane(keys, extra.before, extra.after),
        major: extra.major,
        labelBetween,
    };
}

/** The farm's lanes. Every other pair of neighbours has a footpath. */
export const ROADS: Road[] = [
    road(
        'Harrow Lane',
        ['paddock', 'market_square', 'farmhouse', 'big_barn'],
        ['market_square', 'paddock'],
        {
            major: true,
            before: [
                [800, 1218],
                [796, 1010],
            ],
        },
    ),
    road(
        'Gate Road',
        ['farmhouse', 'windmill_hill', 'ironfield_gate'],
        ['windmill_hill', 'ironfield_gate'],
        {
            major: true,
            after: [
                [1500, 476],
                [1640, 484],
            ],
        },
    ),
    road(
        'Granary Row',
        ['farmhouse', 'granary', 'sheepfold'],
        ['granary', 'farmhouse'],
    ),
    road(
        'Orchard Path',
        ['big_barn', 'orchard', 'wildwood'],
        ['orchard', 'big_barn'],
    ),
    road(
        'Hay Lane',
        ['big_barn', 'hayfield', 'quarry'],
        ['hayfield', 'quarry'],
    ),
    road(
        'Pond Walk',
        ['market_square', 'henhouse', 'duck_pond'],
        ['henhouse', 'market_square'],
    ),
    road(
        'Stable Lane',
        ['market_square', 'stables', 'old_well'],
        ['market_square', 'stables'],
    ),
];

export function pairKey(a: string, b: string): string {
    return a < b ? `${a}|${b}` : `${b}|${a}`;
}

/** Pairs of regions joined by a lane, so footpaths skip them. Harrow Lane runs on to Willowby Farm. */
export const ROAD_LINKS = new Set([
    ...ROADS.flatMap((lane) =>
        lane.keys.slice(1).map((key, i) => pairKey(lane.keys[i], key)),
    ),
    pairKey('paddock', 'willowby'),
]);

/** Hollow Brook, running down the west side and along the south. */
export const BROOK: Point[] = [
    [70, -420],
    [56, -200],
    [74, -20],
    [60, 150],
    [88, 320],
    [54, 510],
    [80, 690],
    [62, 860],
    [132, 1006],
    [420, 1030],
    [760, 1016],
    [1080, 1038],
    [1400, 1018],
    [1640, 1040],
    [1760, 1030],
];

/** The shortest walk between two regions, as region ids, both ends included. */
export function route(
    from: number,
    to: number,
    neighbours: Map<number, number[]>,
): number[] | null {
    const previous = new Map<number, number>([[from, from]]);
    const queue = [from];

    while (queue.length > 0) {
        const current = queue.shift()!;

        if (current === to) {
            const path = [to];

            while (path[0] !== from) {
                path.unshift(previous.get(path[0])!);
            }

            return path;
        }

        for (const next of neighbours.get(current) ?? []) {
            if (!previous.has(next)) {
                previous.set(next, current);
                queue.push(next);
            }
        }
    }

    return null;
}
