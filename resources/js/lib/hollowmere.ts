/**
 * The shape of Hollowmere. The rules see a 5 by 3 grid of regions with the
 * Paddock along the bottom; the map bends that grid into fields, woods and
 * yards, so regions that border each other on the map are exactly the ones
 * that border each other in the rules. Everything here is worked out from
 * fixed seeds, so the map looks the same for every player.
 */

export type Point = [number, number];

/** Everything drawn: Hollowmere, the farms beyond its fences, Irongate and the land round them. */
export const WORLD = { x: -1700, y: -1980, width: 4340, height: 5020 };

/** Hollowmere, its farms and Irongate, for seeing everything at once. */
export const REACH = { x: -1650, y: -1930, width: 4250, height: 4920 };

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
    // The second ring of farms, beyond the wild land round the first.
    ashcombe: {
        polygon: [
            [430, -500],
            [620, -488],
            [800, -500],
            [980, -490],
            [1170, -500],
            [1215, -640],
            [1160, -820],
            [800, -860],
            [440, -820],
            [385, -650],
        ],
        borders: [],
    },
    fennick: {
        polygon: [
            [-680, 180],
            [-690, 400],
            [-672, 620],
            [-685, 840],
            [-860, 880],
            [-1060, 830],
            [-1150, 620],
            [-1130, 380],
            [-1060, 190],
            [-860, 150],
        ],
        borders: [],
    },
    marlow: {
        polygon: [
            [440, 1640],
            [620, 1628],
            [800, 1640],
            [980, 1630],
            [1160, 1642],
            [1210, 1780],
            [1160, 1950],
            [800, 1990],
            [440, 1950],
            [390, 1790],
        ],
        borders: [],
    },
    // The third ring of farms, out at the edges of the valley.
    hartley: {
        polygon: [
            [-778, -850],
            [-777, -762],
            [-880, -708],
            [-1000, -696],
            [-1123, -705],
            [-1191, -775],
            [-1268, -850],
            [-1240, -944],
            [-1114, -984],
            [-1000, -1004],
            [-883, -988],
            [-765, -942],
        ],
        borders: [],
    },
    northfold: {
        polygon: [
            [1218, -1450],
            [1095, -1383],
            [1010, -1307],
            [800, -1291],
            [591, -1307],
            [482, -1378],
            [440, -1450],
            [454, -1529],
            [593, -1591],
            [800, -1616],
            [987, -1578],
            [1145, -1529],
        ],
        borders: [],
    },
    coldharbour: {
        polygon: [
            [2181, -520],
            [2140, -434],
            [2087, -338],
            [1960, -307],
            [1836, -343],
            [1751, -420],
            [1727, -520],
            [1750, -620],
            [1845, -685],
            [1960, -723],
            [2088, -702],
            [2175, -622],
        ],
        borders: [],
    },
    rushmere: {
        polygon: [
            [-760, 1650],
            [-806, 1735],
            [-862, 1832],
            [-1000, 1838],
            [-1130, 1821],
            [-1205, 1740],
            [-1275, 1650],
            [-1208, 1559],
            [-1117, 1496],
            [-1000, 1445],
            [-862, 1468],
            [-788, 1557],
        ],
        borders: [],
    },
    southmere: {
        polygon: [
            [1220, 2500],
            [1148, 2579],
            [969, 2615],
            [800, 2656],
            [588, 2645],
            [465, 2576],
            [440, 2500],
            [470, 2425],
            [599, 2362],
            [800, 2359],
            [1008, 2358],
            [1149, 2420],
        ],
        borders: [],
    },
    copperhill: {
        polygon: [
            [2190, 1500],
            [2175, 1603],
            [2069, 1655],
            [1960, 1709],
            [1834, 1680],
            [1755, 1598],
            [1707, 1500],
            [1775, 1412],
            [1838, 1325],
            [1960, 1322],
            [2066, 1349],
            [2167, 1401],
        ],
        borders: [],
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

/**
 * Irongate, the humans' town just past Ironfield Gate, in three walled
 * districts down the far side of the east fence. Nobody holds it.
 */
export const CITY: Record<string, Point[]> = {
    irongate_rookery: [
        [1518, 132],
        [1640, 112],
        [1780, 122],
        [1888, 150],
        [1906, 258],
        [1880, 350],
        [1700, 364],
        [1520, 348],
    ],
    irongate_yards: [
        [1520, 348],
        [1700, 364],
        [1880, 350],
        [1932, 470],
        [1914, 592],
        [1720, 606],
        [1522, 598],
    ],
    irongate_garrison: [
        [1522, 598],
        [1720, 606],
        [1914, 592],
        [1902, 722],
        [1862, 850],
        [1682, 870],
        [1524, 846],
    ],
};

/** Every region's outline, worked out once. */
export const SHAPES: Record<string, RegionShape> = Object.fromEntries([
    ...Object.entries(LAYOUT).map(([key, [col, row]]) => [
        key,
        regionShape(col, row, key === 'paddock'),
    ]),
    ...Object.entries(FARMS).map(([key, farm]) => [key, shape(farm.polygon)]),
    ...Object.entries(CITY).map(([key, polygon]) => [key, shape(polygon)]),
]);

/**
 * The wild land round each farm, in the order the farm grows into it. A
 * patch is drawn as scrub until it's cleared into a region of its farm.
 */
export const FIELDS: Record<string, { farm: string; polygon: Point[] }> = {
    thornbury_heath: {
        farm: 'thornbury',
        polygon: [
            [130, 96],
            [380, 98],
            [345, -60],
            [400, -236],
            [260, -300],
            [130, -240],
            [110, -80],
        ],
    },
    thornbury_hives: {
        farm: 'thornbury',
        polygon: [
            [412, -242],
            [800, -278],
            [1188, -240],
            [1150, -420],
            [800, -472],
            [452, -430],
        ],
    },
    thornbury_hollow: {
        farm: 'thornbury',
        polygon: [
            [1222, 96],
            [1470, 100],
            [1484, -60],
            [1460, -240],
            [1300, -300],
            [1198, -238],
            [1258, -40],
        ],
    },
    brookside_reeds: {
        farm: 'brookside',
        polygon: [
            [-345, 176],
            [-162, 78],
            [18, 102],
            [30, -100],
            [0, -280],
            [-180, -330],
            [-360, -250],
            [-390, -20],
        ],
    },
    brookside_marsh: {
        farm: 'brookside',
        polygon: [
            [-346, 214],
            [-380, 480],
            [-342, 768],
            [-500, 840],
            [-650, 640],
            [-660, 330],
            [-530, 200],
        ],
    },
    brookside_meadow: {
        farm: 'brookside',
        polygon: [
            [20, 860],
            [-150, 904],
            [-336, 786],
            [-480, 880],
            [-420, 1100],
            [-200, 1180],
            [10, 1120],
            [36, 990],
        ],
    },
    willowby_copse: {
        farm: 'willowby',
        polygon: [
            [420, 1082],
            [388, 1212],
            [470, 1346],
            [300, 1420],
            [150, 1380],
            [120, 1200],
            [190, 1082],
        ],
    },
    willowby_forge: {
        farm: 'willowby',
        polygon: [
            [480, 1350],
            [800, 1386],
            [1124, 1354],
            [1090, 1540],
            [800, 1600],
            [500, 1550],
        ],
    },
    willowby_pits: {
        farm: 'willowby',
        polygon: [
            [1182, 1082],
            [1214, 1220],
            [1134, 1352],
            [1300, 1420],
            [1484, 1360],
            [1484, 1180],
            [1420, 1072],
        ],
    },
    ashcombe_ridge: {
        farm: 'ashcombe',
        polygon: [
            [120, -500],
            [370, -505],
            [340, -650],
            [380, -830],
            [260, -900],
            [130, -840],
            [100, -660],
        ],
    },
    ashcombe_wood: {
        farm: 'ashcombe',
        polygon: [
            [450, -880],
            [800, -895],
            [1150, -880],
            [1120, -1000],
            [800, -1150],
            [480, -1000],
        ],
    },
    ashcombe_spring: {
        farm: 'ashcombe',
        polygon: [
            [1240, -500],
            [1500, -490],
            [1520, -680],
            [1480, -880],
            [1330, -920],
            [1245, -830],
            [1230, -650],
        ],
    },
    fennick_downs: {
        farm: 'fennick',
        polygon: [
            [-700, 125],
            [-860, 115],
            [-1050, 155],
            [-1140, 0],
            [-1100, -220],
            [-900, -300],
            [-720, -220],
            [-680, -40],
        ],
    },
    fennick_moor: {
        farm: 'fennick',
        polygon: [
            [-1175, 380],
            [-1185, 600],
            [-1170, 800],
            [-1260, 760],
            [-1300, 560],
            [-1280, 380],
            [-1210, 300],
        ],
    },
    fennick_brake: {
        farm: 'fennick',
        polygon: [
            [-700, 895],
            [-860, 905],
            [-1050, 865],
            [-1120, 1000],
            [-1050, 1200],
            [-850, 1250],
            [-620, 1210],
            [-520, 1060],
            [-560, 925],
        ],
    },
    marlow_glen: {
        farm: 'marlow',
        polygon: [
            [110, 1460],
            [380, 1440],
            [370, 1620],
            [350, 1800],
            [380, 1960],
            [250, 2000],
            [110, 1930],
            [90, 1700],
        ],
    },
    marlow_leas: {
        farm: 'marlow',
        polygon: [
            [450, 2010],
            [800, 2020],
            [1150, 2010],
            [1120, 2130],
            [800, 2210],
            [480, 2130],
        ],
    },
    marlow_mill: {
        farm: 'marlow',
        polygon: [
            [1235, 1460],
            [1500, 1440],
            [1520, 1650],
            [1490, 1900],
            [1340, 1990],
            [1245, 1940],
            [1235, 1780],
        ],
    },
    // Wild land round the third ring of farms.
    hartley_fold: {
        farm: 'hartley',
        polygon: [
            [-1274, -860],
            [-1326, -749],
            [-1394, -670],
            [-1484, -679],
            [-1550, -753],
            [-1578, -860],
            [-1556, -973],
            [-1482, -1031],
            [-1399, -1028],
            [-1327, -970],
        ],
    },
    hartley_fen: {
        farm: 'hartley',
        polygon: [
            [-780, -1200],
            [-820, -1124],
            [-957, -1098],
            [-1092, -1085],
            [-1186, -1137],
            [-1276, -1200],
            [-1196, -1267],
            [-1097, -1324],
            [-945, -1321],
            [-827, -1273],
        ],
    },
    hartley_rise: {
        farm: 'hartley',
        polygon: [
            [-370, -1180],
            [-387, -1104],
            [-494, -1058],
            [-619, -1071],
            [-732, -1105],
            [-772, -1180],
            [-715, -1247],
            [-618, -1288],
            [-495, -1301],
            [-410, -1245],
        ],
    },
    northfold_heath: {
        farm: 'northfold',
        polygon: [
            [403, -1640],
            [379, -1565],
            [276, -1528],
            [153, -1507],
            [53, -1561],
            [4, -1640],
            [69, -1711],
            [152, -1777],
            [275, -1750],
            [388, -1720],
        ],
    },
    northfold_hives: {
        farm: 'northfold',
        polygon: [
            [1129, -1780],
            [1051, -1713],
            [888, -1681],
            [715, -1684],
            [586, -1723],
            [522, -1780],
            [561, -1844],
            [710, -1882],
            [889, -1881],
            [1035, -1843],
        ],
    },
    northfold_crag: {
        farm: 'northfold',
        polygon: [
            [1593, -1640],
            [1543, -1563],
            [1444, -1513],
            [1312, -1504],
            [1216, -1563],
            [1185, -1640],
            [1227, -1712],
            [1321, -1758],
            [1446, -1772],
            [1531, -1711],
        ],
    },
    coldharbour_scar: {
        farm: 'coldharbour',
        polygon: [
            [2540, -560],
            [2513, -431],
            [2431, -352],
            [2333, -366],
            [2266, -449],
            [2246, -560],
            [2260, -676],
            [2332, -755],
            [2431, -769],
            [2491, -668],
        ],
    },
    coldharbour_mine: {
        farm: 'coldharbour',
        polygon: [
            [2180, -900],
            [2125, -835],
            [2020, -798],
            [1900, -799],
            [1789, -832],
            [1717, -900],
            [1793, -966],
            [1887, -1023],
            [2035, -1027],
            [2147, -974],
        ],
    },
    coldharbour_moss: {
        farm: 'coldharbour',
        polygon: [
            [2176, -170],
            [2156, -112],
            [2030, -82],
            [1896, -90],
            [1788, -119],
            [1721, -170],
            [1785, -222],
            [1891, -257],
            [2035, -265],
            [2134, -222],
        ],
    },
    rushmere_bog: {
        farm: 'rushmere',
        polygon: [
            [-1277, 1660],
            [-1327, 1769],
            [-1395, 1845],
            [-1482, 1833],
            [-1573, 1789],
            [-1599, 1660],
            [-1555, 1548],
            [-1483, 1485],
            [-1396, 1478],
            [-1305, 1529],
        ],
    },
    rushmere_banks: {
        farm: 'rushmere',
        polygon: [
            [-769, 2020],
            [-818, 2089],
            [-932, 2130],
            [-1078, 2146],
            [-1204, 2097],
            [-1220, 2020],
            [-1175, 1954],
            [-1078, 1894],
            [-923, 1896],
            [-800, 1944],
        ],
    },
    rushmere_carr: {
        farm: 'rushmere',
        polygon: [
            [-341, 2000],
            [-396, 2071],
            [-499, 2113],
            [-629, 2127],
            [-730, 2074],
            [-751, 2000],
            [-706, 1937],
            [-623, 1883],
            [-501, 1891],
            [-384, 1923],
        ],
    },
    southmere_dell: {
        farm: 'southmere',
        polygon: [
            [421, 2700],
            [383, 2777],
            [286, 2832],
            [159, 2822],
            [55, 2778],
            [23, 2700],
            [46, 2618],
            [165, 2591],
            [289, 2562],
            [363, 2632],
        ],
    },
    southmere_gardens: {
        farm: 'southmere',
        polygon: [
            [1129, 2840],
            [1042, 2905],
            [891, 2943],
            [708, 2944],
            [545, 2908],
            [493, 2840],
            [535, 2769],
            [704, 2731],
            [884, 2745],
            [1017, 2782],
        ],
    },
    southmere_weald: {
        farm: 'southmere',
        polygon: [
            [1600, 2700],
            [1536, 2774],
            [1441, 2822],
            [1317, 2827],
            [1209, 2781],
            [1193, 2700],
            [1218, 2624],
            [1313, 2567],
            [1439, 2581],
            [1532, 2628],
        ],
    },
    copperhill_workings: {
        farm: 'copperhill',
        polygon: [
            [2539, 1500],
            [2512, 1628],
            [2428, 1695],
            [2329, 1710],
            [2268, 1608],
            [2228, 1500],
            [2271, 1394],
            [2329, 1291],
            [2426, 1313],
            [2513, 1371],
        ],
    },
    copperhill_tor: {
        farm: 'copperhill',
        polygon: [
            [2169, 1130],
            [2142, 1202],
            [2031, 1249],
            [1899, 1232],
            [1778, 1202],
            [1765, 1130],
            [1789, 1062],
            [1898, 1026],
            [2029, 1015],
            [2155, 1053],
        ],
    },
    copperhill_slag: {
        farm: 'copperhill',
        polygon: [
            [2187, 1870],
            [2120, 1933],
            [2036, 1997],
            [1900, 1971],
            [1783, 1940],
            [1761, 1870],
            [1763, 1792],
            [1891, 1754],
            [2027, 1757],
            [2138, 1800],
        ],
    },
};

export const FIELD_SHAPES: Record<string, RegionShape> = Object.fromEntries(
    Object.entries(FIELDS).map(([key, field]) => [key, shape(field.polygon)]),
);

function anyShape(key: string): RegionShape | undefined {
    return SHAPES[key] ?? FIELD_SHAPES[key];
}

export function shapeOf(
    key: string,
    col: number,
    row: number,
    safe: boolean,
): RegionShape {
    return anyShape(key) ?? regionShape(col, row, safe);
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
    // Gate Road ends in the Iron Yards.
    irongate_rookery: [-60, 20],
    irongate_yards: [-110, 6],
    irongate_garrison: [-60, -10],
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
    const shape = anyShape(key);
    const [dx, dy] = PLOTS[key] ?? (FIELDS[key] ? [30, 40] : [60, 40]);

    return shape ? [shape.centre[0] + dx, shape.centre[1] + dy] : hub(key);
}

/** The spot in a region where its lanes meet and animals gather. */
export function hub(key: string): Point {
    const shape = anyShape(key);

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
