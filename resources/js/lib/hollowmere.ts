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
    // The town grew east: the smiths, the tavern and the gallows.
    irongate_smithy: [
        [1888, 150],
        [2040, 130],
        [2200, 160],
        [2240, 260],
        [2200, 350],
        [2040, 366],
        [1880, 350],
        [1906, 258],
    ],
    irongate_tavern: [
        [1880, 350],
        [2040, 366],
        [2200, 350],
        [2260, 470],
        [2230, 592],
        [2060, 606],
        [1914, 592],
        [1932, 470],
    ],
    irongate_gallows: [
        [1914, 592],
        [2060, 606],
        [2230, 592],
        [2210, 722],
        [2160, 850],
        [2000, 870],
        [1862, 850],
        [1902, 722],
    ],
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

/**
 * The frontier: wild land filling the valley round the farms, woods, ponds,
 * scrub, stony ground, commons and a few rare spots. Anyone gathers there;
 * factions can hold it.
 */
export const FRONTIER: Record<string, Point[]> = {
    glitter_gully: [
        [-1114, -1660],
        [-1161, -1565],
        [-1241, -1487],
        [-1380, -1465],
        [-1513, -1491],
        [-1608, -1562],
        [-1623, -1660],
        [-1618, -1761],
        [-1500, -1824],
        [-1380, -1878],
        [-1262, -1847],
        [-1154, -1754],
    ],
    blackthorn_wood: [
        [-526, -1660],
        [-573, -1555],
        [-648, -1499],
        [-780, -1474],
        [-920, -1493],
        [-1003, -1564],
        [-1047, -1660],
        [-1023, -1752],
        [-898, -1846],
        [-780, -1866],
        [-659, -1832],
        [-567, -1767],
    ],
    mirror_ponds: [
        [2239, -1660],
        [2211, -1561],
        [2112, -1479],
        [1980, -1465],
        [1839, -1487],
        [1762, -1554],
        [1701, -1660],
        [1760, -1767],
        [1862, -1835],
        [1980, -1856],
        [2099, -1843],
        [2218, -1767],
    ],
    bramble_heath: [
        [62, -1420],
        [55, -1315],
        [-58, -1247],
        [-180, -1236],
        [-301, -1250],
        [-410, -1320],
        [-434, -1420],
        [-423, -1529],
        [-300, -1593],
        [-180, -1629],
        [-49, -1588],
        [60, -1519],
    ],
    grey_scree: [
        [1758, -1300],
        [1738, -1209],
        [1642, -1119],
        [1500, -1107],
        [1372, -1116],
        [1294, -1206],
        [1261, -1300],
        [1267, -1400],
        [1382, -1470],
        [1500, -1492],
        [1635, -1474],
        [1723, -1407],
    ],
    long_meadow: [
        [2596, -1300],
        [2556, -1197],
        [2467, -1141],
        [2340, -1114],
        [2206, -1126],
        [2102, -1194],
        [2064, -1300],
        [2102, -1398],
        [2214, -1463],
        [2340, -1518],
        [2458, -1485],
        [2586, -1402],
    ],
    rookhollow_wood: [
        [104, -940],
        [56, -839],
        [-47, -780],
        [-180, -729],
        [-302, -752],
        [-408, -840],
        [-438, -940],
        [-386, -1032],
        [-313, -1096],
        [-180, -1143],
        [-57, -1128],
        [66, -1047],
    ],
    heron_mere: [
        [-271, -580],
        [-301, -480],
        [-413, -395],
        [-540, -360],
        [-674, -423],
        [-764, -480],
        [-813, -580],
        [-774, -678],
        [-680, -736],
        [-540, -781],
        [-420, -744],
        [-315, -678],
    ],
    gorse_common: [
        [-1026, -460],
        [-1031, -356],
        [-1133, -276],
        [-1260, -254],
        [-1396, -286],
        [-1471, -363],
        [-1520, -460],
        [-1468, -551],
        [-1384, -617],
        [-1260, -658],
        [-1135, -646],
        [-1018, -560],
    ],
    flint_downs: [
        [-1109, 140],
        [-1136, 232],
        [-1247, 320],
        [-1380, 335],
        [-1504, 328],
        [-1622, 242],
        [-1628, 140],
        [-1586, 46],
        [-1506, -21],
        [-1380, -57],
        [-1249, -47],
        [-1154, 43],
    ],
    sheep_down: [
        [-1112, 1220],
        [-1155, 1314],
        [-1242, 1402],
        [-1380, 1422],
        [-1510, 1410],
        [-1607, 1324],
        [-1650, 1220],
        [-1601, 1115],
        [-1499, 1056],
        [-1380, 1012],
        [-1262, 1051],
        [-1162, 1130],
    ],
    gallows_copse: [
        [-164, 1460],
        [-183, 1556],
        [-292, 1638],
        [-420, 1663],
        [-560, 1645],
        [-646, 1557],
        [-691, 1460],
        [-624, 1354],
        [-544, 1283],
        [-420, 1256],
        [-281, 1288],
        [-194, 1351],
    ],
    silt_pools: [
        [184, 2180],
        [156, 2284],
        [69, 2355],
        [-60, 2373],
        [-178, 2368],
        [-290, 2275],
        [-326, 2180],
        [-269, 2082],
        [-182, 2012],
        [-60, 1988],
        [68, 2007],
        [154, 2079],
    ],
    thistle_down: [
        [1772, 2180],
        [1740, 2271],
        [1632, 2361],
        [1500, 2382],
        [1369, 2356],
        [1279, 2278],
        [1239, 2180],
        [1284, 2079],
        [1382, 1998],
        [1500, 1991],
        [1619, 2021],
        [1736, 2074],
    ],
    cairn_hill: [
        [2385, 2180],
        [2324, 2285],
        [2219, 2337],
        [2100, 2384],
        [1968, 2365],
        [1881, 2270],
        [1856, 2180],
        [1891, 2073],
        [1983, 2023],
        [2100, 1969],
        [2226, 1995],
        [2336, 2088],
    ],
    old_copper_seam: [
        [-1118, 2300],
        [-1136, 2397],
        [-1245, 2485],
        [-1380, 2512],
        [-1505, 2486],
        [-1624, 2406],
        [-1655, 2300],
        [-1586, 2199],
        [-1516, 2138],
        [-1380, 2113],
        [-1247, 2125],
        [-1165, 2205],
    ],
    cowslip_lea: [
        [-523, 2420],
        [-541, 2523],
        [-660, 2605],
        [-780, 2617],
        [-904, 2598],
        [-1017, 2511],
        [-1020, 2420],
        [-1026, 2315],
        [-899, 2260],
        [-780, 2219],
        [-657, 2243],
        [-542, 2317],
    ],
    ashwood: [
        [-47, 2660],
        [-60, 2769],
        [-175, 2822],
        [-300, 2859],
        [-425, 2819],
        [-524, 2769],
        [-558, 2660],
        [-512, 2567],
        [-418, 2494],
        [-300, 2443],
        [-168, 2477],
        [-77, 2557],
    ],
    eelwater: [
        [2131, 2660],
        [2076, 2753],
        [2003, 2844],
        [1860, 2868],
        [1733, 2821],
        [1635, 2754],
        [1613, 2660],
        [1619, 2555],
        [1736, 2496],
        [1860, 2463],
        [1991, 2494],
        [2094, 2566],
    ],
    wild_hives: [
        [-1146, 2780],
        [-1147, 2885],
        [-1248, 2940],
        [-1380, 2981],
        [-1512, 2952],
        [-1604, 2888],
        [-1634, 2780],
        [-1609, 2678],
        [-1497, 2600],
        [-1380, 2599],
        [-1250, 2621],
        [-1147, 2684],
    ],
    briar_scrub: [
        [507, -1180],
        [489, -1093],
        [414, -1047],
        [300, -1007],
        [198, -1035],
        [109, -1092],
        [106, -1180],
        [125, -1273],
        [186, -1321],
        [300, -1345],
        [410, -1317],
        [487, -1271],
    ],
    rattle_rocks: [
        [2568, 2780],
        [2510, 2857],
        [2454, 2934],
        [2340, 2950],
        [2230, 2940],
        [2162, 2857],
        [2110, 2780],
        [2170, 2690],
        [2230, 2639],
        [2340, 2601],
        [2444, 2643],
        [2536, 2692],
    ],
    windy_common: [
        [-121, -1780],
        [-148, -1714],
        [-216, -1663],
        [-300, -1652],
        [-384, -1652],
        [-458, -1709],
        [-454, -1780],
        [-459, -1855],
        [-388, -1903],
        [-300, -1933],
        [-220, -1903],
        [-167, -1851],
    ],
    hazel_hanger: [
        [3, 1820],
        [-41, 1893],
        [-92, 1929],
        [-180, 1952],
        [-260, 1935],
        [-329, 1894],
        [-361, 1820],
        [-341, 1755],
        [-260, 1687],
        [-180, 1666],
        [-102, 1697],
        [-44, 1752],
    ],
    berry_brake: [
        [-722, 2780],
        [-738, 2851],
        [-810, 2892],
        [-900, 2906],
        [-977, 2910],
        [-1043, 2856],
        [-1054, 2780],
        [-1050, 2714],
        [-981, 2667],
        [-900, 2650],
        [-814, 2650],
        [-763, 2714],
    ],
    // Smaller patches filling the gaps: glades, camp fields, thickets, springs, spinneys and knolls.
    ash_glade: [
        [2574, -1790],
        [2537, -1713],
        [2452, -1671],
        [2363, -1680],
        [2281, -1717],
        [2245, -1790],
        [2279, -1857],
        [2368, -1902],
        [2457, -1910],
        [2526, -1865],
    ],
    bramble_thicket: [
        [-1370, -1300],
        [-1395, -1227],
        [-1462, -1189],
        [-1556, -1188],
        [-1621, -1235],
        [-1653, -1300],
        [-1625, -1373],
        [-1557, -1425],
        [-1460, -1419],
        [-1386, -1377],
    ],
    crow_spring: [
        [2071, -1230],
        [2040, -1161],
        [1967, -1118],
        [1878, -1115],
        [1807, -1164],
        [1771, -1230],
        [1790, -1303],
        [1874, -1336],
        [1967, -1339],
        [2034, -1307],
    ],
    dun_spinney: [
        [1231, -1160],
        [1206, -1094],
        [1130, -1055],
        [1030, -1055],
        [962, -1084],
        [943, -1160],
        [965, -1236],
        [1035, -1274],
        [1122, -1285],
        [1203, -1229],
    ],
    elder_camp_field: [
        [2505, -950],
        [2463, -882],
        [2387, -834],
        [2296, -842],
        [2229, -881],
        [2188, -950],
        [2215, -1026],
        [2293, -1060],
        [2385, -1066],
        [2471, -1016],
    ],
    fern_knoll: [
        [54, -530],
        [15, -461],
        [-64, -423],
        [-160, -416],
        [-238, -466],
        [-247, -530],
        [-232, -595],
        [-154, -643],
        [-66, -634],
        [18, -594],
    ],
    gorse_glade: [
        [1781, -320],
        [1761, -249],
        [1687, -202],
        [1597, -214],
        [1527, -246],
        [1496, -320],
        [1527, -395],
        [1594, -443],
        [1685, -441],
        [1768, -393],
    ],
    hare_thicket: [
        [-1348, -180],
        [-1399, -107],
        [-1466, -58],
        [-1559, -75],
        [-1639, -105],
        [-1648, -180],
        [-1636, -257],
        [-1556, -286],
        [-1465, -296],
        [-1377, -252],
    ],
    ivy_spring: [
        [-377, -180],
        [-416, -105],
        [-485, -70],
        [-575, -76],
        [-641, -104],
        [-672, -180],
        [-654, -255],
        [-573, -304],
        [-483, -286],
        [-420, -246],
    ],
    juniper_spinney: [
        [2551, -180],
        [2531, -109],
        [2453, -68],
        [2365, -58],
        [2300, -108],
        [2264, -180],
        [2299, -244],
        [2368, -296],
        [2457, -289],
        [2534, -247],
    ],
    kestrel_camp_field: [
        [2273, 30],
        [2240, 98],
        [2172, 133],
        [2080, 137],
        [2020, 107],
        [1975, 30],
        [2006, -44],
        [2086, -80],
        [2181, -82],
        [2255, -41],
    ],
    lark_knoll: [
        [-1365, 520],
        [-1397, 588],
        [-1465, 645],
        [-1555, 629],
        [-1635, 591],
        [-1654, 520],
        [-1628, 450],
        [-1558, 398],
        [-1464, 403],
        [-1380, 444],
    ],
    moss_glade: [
        [-1302, 800],
        [-1310, 874],
        [-1389, 923],
        [-1482, 915],
        [-1550, 872],
        [-1593, 800],
        [-1560, 733],
        [-1483, 677],
        [-1393, 686],
        [-1312, 727],
    ],
    nettle_thicket: [
        [2483, 1080],
        [2469, 1147],
        [2391, 1199],
        [2290, 1198],
        [2212, 1145],
        [2199, 1080],
        [2218, 1007],
        [2290, 971],
        [2382, 964],
        [2454, 1008],
    ],
    oak_spring: [
        [2571, 1850],
        [2528, 1916],
        [2458, 1960],
        [2367, 1965],
        [2285, 1924],
        [2251, 1850],
        [2277, 1775],
        [2367, 1746],
        [2457, 1741],
        [2525, 1775],
    ],
    pike_spinney: [
        [-1355, 1990],
        [-1393, 2063],
        [-1463, 2103],
        [-1559, 2114],
        [-1634, 2056],
        [-1648, 1990],
        [-1632, 1921],
        [-1558, 1875],
        [-1467, 1872],
        [-1392, 1916],
    ],
    quill_camp_field: [
        [545, 2270],
        [491, 2347],
        [424, 2386],
        [336, 2386],
        [267, 2339],
        [235, 2270],
        [263, 2195],
        [338, 2158],
        [428, 2147],
        [494, 2206],
    ],
    reed_knoll: [
        [2570, 2410],
        [2529, 2475],
        [2458, 2517],
        [2360, 2520],
        [2282, 2475],
        [2262, 2410],
        [2292, 2337],
        [2364, 2296],
        [2459, 2302],
        [2530, 2344],
    ],
    sedge_glade: [
        [-967, -1440],
        [-991, -1387],
        [-1057, -1355],
        [-1128, -1349],
        [-1176, -1380],
        [-1199, -1440],
        [-1184, -1499],
        [-1128, -1536],
        [-1052, -1537],
        [-990, -1500],
    ],
    thorn_thicket: [
        [1759, -1020],
        [1727, -964],
        [1673, -931],
        [1603, -922],
        [1543, -966],
        [1534, -1020],
        [1544, -1071],
        [1606, -1114],
        [1672, -1107],
        [1742, -1073],
    ],
    umber_spring: [
        [-478, -950],
        [-507, -897],
        [-567, -862],
        [-635, -867],
        [-699, -899],
        [-721, -950],
        [-693, -1006],
        [-636, -1045],
        [-562, -1047],
        [-502, -1004],
    ],
    vetch_spinney: [
        [1754, -40],
        [1734, 14],
        [1678, 43],
        [1605, 57],
        [1546, 10],
        [1521, -40],
        [1542, -97],
        [1605, -126],
        [1677, -136],
        [1730, -93],
    ],
    willow_camp_field: [
        [-419, 100],
        [-442, 152],
        [-496, 197],
        [-568, 198],
        [-629, 157],
        [-649, 100],
        [-618, 47],
        [-568, 7],
        [-498, 12],
        [-443, 42],
    ],
    yarrow_knoll: [
        [1755, 1010],
        [1724, 1066],
        [1673, 1102],
        [1601, 1091],
        [1550, 1065],
        [1527, 1010],
        [1542, 949],
        [1604, 913],
        [1678, 924],
        [1728, 950],
    ],
    alder_glade: [
        [84, 1290],
        [60, 1346],
        [-4, 1389],
        [-75, 1372],
        [-142, 1344],
        [-161, 1290],
        [-132, 1233],
        [-77, 1203],
        [-4, 1206],
        [62, 1232],
    ],
    birch_thicket: [
        [1747, 1290],
        [1741, 1343],
        [1672, 1385],
        [1607, 1374],
        [1555, 1348],
        [1525, 1290],
        [1554, 1236],
        [1604, 1200],
        [1677, 1201],
        [1730, 1238],
    ],
    clover_spring: [
        [65, 1570],
        [47, 1630],
        [-6, 1653],
        [-76, 1659],
        [-141, 1622],
        [-145, 1570],
        [-138, 1512],
        [-76, 1475],
        [-4, 1477],
        [53, 1512],
    ],
    dock_spinney: [
        [1198, 2270],
        [1173, 2328],
        [1118, 2354],
        [1044, 2352],
        [989, 2325],
        [962, 2270],
        [996, 2215],
        [1043, 2189],
        [1112, 2180],
        [1180, 2209],
    ],
    ember_camp_field: [
        [226, 2480],
        [186, 2538],
        [133, 2575],
        [63, 2574],
        [-1, 2536],
        [-18, 2480],
        [0, 2425],
        [66, 2389],
        [136, 2385],
        [200, 2427],
    ],
    flax_knoll: [
        [1555, 2480],
        [1522, 2530],
        [1469, 2572],
        [1396, 2565],
        [1338, 2535],
        [1306, 2480],
        [1332, 2419],
        [1391, 2388],
        [1465, 2383],
        [1528, 2427],
    ],
    gale_glade: [
        [-489, 2900],
        [-514, 2957],
        [-568, 2991],
        [-632, 2988],
        [-687, 2956],
        [-719, 2900],
        [-701, 2843],
        [-634, 2808],
        [-562, 2809],
        [-505, 2849],
    ],
    heather_thicket: [
        [68, 2900],
        [49, 2955],
        [-7, 2982],
        [-77, 2991],
        [-135, 2954],
        [-164, 2900],
        [-130, 2842],
        [-78, 2808],
        [-1, 2811],
        [45, 2839],
    ],
    iris_spring: [
        [1688, 2900],
        [1660, 2952],
        [1604, 2986],
        [1535, 2989],
        [1477, 2959],
        [1454, 2900],
        [1473, 2842],
        [1536, 2809],
        [1602, 2802],
        [1667, 2847],
    ],
    kale_spinney: [
        [-999, -1860],
        [-1028, -1817],
        [-1062, -1785],
        [-1118, -1794],
        [-1156, -1819],
        [-1169, -1860],
        [-1155, -1902],
        [-1117, -1930],
        [-1066, -1931],
        [-1019, -1901],
    ],
    linden_camp_field: [
        [53, -1860],
        [23, -1820],
        [-13, -1788],
        [-65, -1794],
        [-110, -1818],
        [-130, -1860],
        [-110, -1905],
        [-67, -1928],
        [-11, -1937],
        [23, -1907],
    ],
    mallow_knoll: [
        [318, -1860],
        [302, -1815],
        [267, -1795],
        [213, -1789],
        [168, -1814],
        [162, -1860],
        [165, -1904],
        [211, -1927],
        [267, -1933],
        [315, -1904],
    ],
    nut_glade: [
        [1305, -1860],
        [1285, -1819],
        [1247, -1785],
        [1195, -1795],
        [1146, -1819],
        [1137, -1860],
        [1147, -1903],
        [1196, -1929],
        [1246, -1926],
        [1290, -1903],
    ],
    otter_thicket: [
        [1653, -1860],
        [1644, -1817],
        [1597, -1788],
        [1546, -1796],
        [1498, -1812],
        [1491, -1860],
        [1500, -1908],
        [1542, -1938],
        [1595, -1933],
        [1642, -1903],
    ],
    poppy_spring: [
        [-648, -1370],
        [-671, -1325],
        [-713, -1303],
        [-768, -1298],
        [-809, -1325],
        [-819, -1370],
        [-810, -1412],
        [-766, -1439],
        [-712, -1448],
        [-672, -1418],
    ],
    rush_spinney: [
        [-1207, -1090],
        [-1232, -1045],
        [-1276, -1018],
        [-1324, -1013],
        [-1367, -1046],
        [-1391, -1090],
        [-1367, -1132],
        [-1329, -1164],
        [-1274, -1156],
        [-1231, -1135],
    ],
    sorrel_camp_field: [
        [1717, -810],
        [1704, -764],
        [1664, -734],
        [1612, -736],
        [1573, -768],
        [1558, -810],
        [1568, -853],
        [1612, -888],
        [1667, -882],
        [1708, -852],
    ],
    teasel_knoll: [
        [-788, -460],
        [-816, -417],
        [-852, -390],
        [-908, -393],
        [-942, -414],
        [-962, -460],
        [-951, -504],
        [-905, -528],
        [-854, -533],
        [-813, -501],
    ],
    vole_glade: [
        [260, -390],
        [244, -345],
        [194, -323],
        [143, -324],
        [104, -342],
        [93, -390],
        [106, -438],
        [144, -456],
        [199, -459],
        [241, -437],
    ],
    wren_thicket: [
        [1449, -390],
        [1435, -344],
        [1386, -320],
        [1335, -312],
        [1293, -349],
        [1271, -390],
        [1294, -434],
        [1334, -455],
        [1388, -455],
        [1427, -434],
    ],
    badger_spring: [
        [-660, -320],
        [-676, -280],
        [-716, -255],
        [-765, -245],
        [-805, -273],
        [-820, -320],
        [-805, -360],
        [-766, -387],
        [-712, -396],
        [-668, -365],
    ],
    cress_spinney: [
        [2292, -320],
        [2267, -275],
        [2226, -246],
        [2172, -244],
        [2136, -275],
        [2116, -320],
        [2127, -361],
        [2174, -389],
        [2224, -392],
        [2271, -363],
    ],
    daisy_camp_field: [
        [1935, 30],
        [1920, 70],
        [1879, 98],
        [1822, 102],
        [1785, 70],
        [1773, 30],
        [1780, -10],
        [1823, -43],
        [1876, -48],
        [1919, -16],
    ],
    finch_knoll: [
        [2360, 800],
        [2345, 845],
        [2297, 871],
        [2241, 874],
        [2196, 844],
        [2181, 800],
        [2206, 752],
        [2243, 732],
        [2297, 730],
        [2337, 754],
    ],
    grouse_glade: [
        [2287, 1290],
        [2275, 1337],
        [2228, 1357],
        [2173, 1354],
        [2125, 1333],
        [2108, 1290],
        [2132, 1247],
        [2175, 1221],
        [2228, 1218],
        [2264, 1244],
    ],
    holly_thicket: [
        [-873, 1360],
        [-877, 1401],
        [-923, 1434],
        [-976, 1424],
        [-1014, 1401],
        [-1030, 1360],
        [-1015, 1315],
        [-974, 1291],
        [-925, 1284],
        [-885, 1315],
    ],
    lynx_spring: [
        [1725, 1640],
        [1715, 1685],
        [1667, 1715],
        [1613, 1704],
        [1576, 1682],
        [1559, 1640],
        [1577, 1597],
        [1612, 1570],
        [1669, 1567],
        [1704, 1598],
    ],
    marten_spinney: [
        [-581, 1780],
        [-600, 1827],
        [-642, 1846],
        [-699, 1850],
        [-735, 1824],
        [-751, 1780],
        [-736, 1738],
        [-695, 1709],
        [-645, 1716],
        [-608, 1739],
    ],
    newt_camp_field: [
        [-368, 1780],
        [-396, 1822],
        [-432, 1847],
        [-485, 1858],
        [-523, 1828],
        [-545, 1780],
        [-524, 1732],
        [-484, 1702],
        [-433, 1706],
        [-393, 1736],
    ],
    owl_knoll: [
        [-1142, 1850],
        [-1161, 1894],
        [-1202, 1921],
        [-1256, 1914],
        [-1298, 1890],
        [-1308, 1850],
        [-1298, 1808],
        [-1256, 1772],
        [-1202, 1778],
        [-1162, 1808],
    ],
    plover_glade: [
        [1718, 1850],
        [1711, 1891],
        [1668, 1919],
        [1612, 1915],
        [1569, 1895],
        [1550, 1850],
        [1565, 1802],
        [1612, 1775],
        [1669, 1781],
        [1713, 1809],
    ],
    robin_thicket: [
        [2557, 2060],
        [2552, 2100],
        [2508, 2126],
        [2455, 2137],
        [2414, 2101],
        [2394, 2060],
        [2416, 2014],
        [2454, 1993],
        [2508, 1990],
        [2545, 2017],
    ],
    stoat_spring: [
        [-310, 2340],
        [-320, 2382],
        [-363, 2407],
        [-418, 2418],
        [-463, 2386],
        [-470, 2340],
        [-465, 2299],
        [-418, 2271],
        [-364, 2275],
        [-328, 2294],
    ],
    tansy_spinney: [
        [462, 2900],
        [454, 2945],
        [408, 2975],
        [356, 2973],
        [318, 2942],
        [292, 2900],
        [311, 2852],
        [354, 2830],
        [406, 2834],
        [455, 2858],
    ],
};

/** What kind of land each frontier region is, for drawing it. */
export const FRONTIER_KIND: Record<
    string,
    'woods' | 'ponds' | 'scrub' | 'stone' | 'grass' | 'deposit'
> = {
    glitter_gully: 'deposit',
    blackthorn_wood: 'woods',
    mirror_ponds: 'ponds',
    bramble_heath: 'scrub',
    grey_scree: 'stone',
    long_meadow: 'grass',
    rookhollow_wood: 'woods',
    heron_mere: 'ponds',
    gorse_common: 'scrub',
    flint_downs: 'stone',
    sheep_down: 'grass',
    gallows_copse: 'woods',
    silt_pools: 'ponds',
    thistle_down: 'scrub',
    cairn_hill: 'stone',
    old_copper_seam: 'deposit',
    cowslip_lea: 'grass',
    ashwood: 'woods',
    eelwater: 'ponds',
    wild_hives: 'deposit',
    briar_scrub: 'scrub',
    rattle_rocks: 'stone',
    windy_common: 'grass',
    hazel_hanger: 'woods',
    berry_brake: 'deposit',
    ash_glade: 'grass',
    bramble_thicket: 'scrub',
    crow_spring: 'ponds',
    dun_spinney: 'woods',
    elder_camp_field: 'grass',
    fern_knoll: 'stone',
    gorse_glade: 'grass',
    hare_thicket: 'scrub',
    ivy_spring: 'ponds',
    juniper_spinney: 'woods',
    kestrel_camp_field: 'grass',
    lark_knoll: 'stone',
    moss_glade: 'grass',
    nettle_thicket: 'scrub',
    oak_spring: 'ponds',
    pike_spinney: 'woods',
    quill_camp_field: 'grass',
    reed_knoll: 'stone',
    sedge_glade: 'grass',
    thorn_thicket: 'scrub',
    umber_spring: 'ponds',
    vetch_spinney: 'woods',
    willow_camp_field: 'grass',
    yarrow_knoll: 'stone',
    alder_glade: 'grass',
    birch_thicket: 'scrub',
    clover_spring: 'ponds',
    dock_spinney: 'woods',
    ember_camp_field: 'grass',
    flax_knoll: 'stone',
    gale_glade: 'grass',
    heather_thicket: 'scrub',
    iris_spring: 'ponds',
    kale_spinney: 'woods',
    linden_camp_field: 'grass',
    mallow_knoll: 'stone',
    nut_glade: 'grass',
    otter_thicket: 'scrub',
    poppy_spring: 'ponds',
    rush_spinney: 'woods',
    sorrel_camp_field: 'grass',
    teasel_knoll: 'stone',
    vole_glade: 'grass',
    wren_thicket: 'scrub',
    badger_spring: 'ponds',
    cress_spinney: 'woods',
    daisy_camp_field: 'grass',
    finch_knoll: 'stone',
    grouse_glade: 'grass',
    holly_thicket: 'scrub',
    lynx_spring: 'ponds',
    marten_spinney: 'woods',
    newt_camp_field: 'grass',
    owl_knoll: 'stone',
    plover_glade: 'grass',
    robin_thicket: 'scrub',
    stoat_spring: 'ponds',
    tansy_spinney: 'woods',
};

/** Every region's outline, worked out once. */
export const SHAPES: Record<string, RegionShape> = Object.fromEntries([
    ...Object.entries(LAYOUT).map(([key, [col, row]]) => [
        key,
        regionShape(col, row, key === 'paddock'),
    ]),
    ...Object.entries(FARMS).map(([key, farm]) => [key, shape(farm.polygon)]),
    ...Object.entries(CITY).map(([key, polygon]) => [key, shape(polygon)]),
    ...Object.entries(FRONTIER).map(([key, polygon]) => [key, shape(polygon)]),
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
