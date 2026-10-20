import type { Point } from '@/lib/hollowmere';
import { inside, seeded } from '@/lib/hollowmere';
import type { MapRegion } from '@/types/game';

/**
 * Where Ironfield's town stands, for both maps: rows of buildings along each
 * grey block, a cross street every few, the names kept clear. Plain numbers,
 * so the flat map doesn't need three.js to draw it.
 */

/** Low ones near the road from Irongate, taller ones deeper in. */
export const LOW = [
    '1Story_GableRoof_Mat',
    '1Story_Sign_Mat',
    '2Story_Mat',
    '2Story_Sign_Mat',
    '2Story_Slim_Mat',
    '2Story_Columns_Mat',
] as const;
export const MIDDLE = [
    '2Story_Balcony_Mat',
    '2Story_Wide_Mat',
    '3Story_Balcony_Mat',
    '3Story_Slim_Mat',
    '3Story_Small_Mat',
] as const;
export const TALL = [
    '4Story_Mat',
    '4Story_Center_Mat',
    '6Story_Stack_Mat',
] as const;

export const CITY_MODELS = [...LOW, ...MIDDLE, ...TALL] as const;

export type CityModel = (typeof CITY_MODELS)[number];

/** The distance between houses along a street, and between streets. */
const PITCH_ALONG = 74;
const PITCH_ACROSS = 86;

export type Lot = { model: CityModel; x: number; y: number; turn: number };

/** Where every building stands: rows along each block, a cross street every few, the names left clear. */
export function cityLots(
    regions: MapRegion[],
    shapeOf: (region: MapRegion) => { polygon: Point[] },
    labelOf: (region: MapRegion) => Point,
): Lot[] {
    const lots: Lot[] = [];
    for (const region of regions) {
        if (region.ring !== 'ironfield') {
            continue;
        }
        const shape = shapeOf(region);
        const polygon = shape.polygon as Point[];
        const [lx, ly] = labelOf(region);
        const xs = polygon.map((p) => p[0]);
        const ys = polygon.map((p) => p[1]);
        const [x0, x1] = [Math.min(...xs), Math.max(...xs)];
        const [y0, y1] = [Math.min(...ys), Math.max(...ys)];
        // How deep into Ironfield: the further east, the taller.
        const depth = Math.min(1, Math.max(0, (x0 - 2300) / 1400));
        let row = 0;
        for (let y = y0 + PITCH_ALONG / 2; y < y1; y += PITCH_ALONG, row++) {
            // A cross street every fifth row.
            if (row % 5 === 4) {
                continue;
            }
            let col = 0;
            for (
                let x = x0 + PITCH_ACROSS / 2;
                x < x1;
                x += PITCH_ACROSS, col++
            ) {
                const key = `${region.key}:${row}:${col}`;
                const p: Point = [
                    x + (seeded(`city:x:${key}`) - 0.5) * 8,
                    y + (seeded(`city:y:${key}`) - 0.5) * 8,
                ];
                // Inside the block, with room to spare at its edge.
                if (
                    ![
                        p,
                        [p[0] - 30, p[1]],
                        [p[0] + 30, p[1]],
                        [p[0], p[1] - 30],
                        [p[0], p[1] + 30],
                    ].every((q) => inside(q as Point, polygon))
                ) {
                    continue;
                }
                // The name and its line underneath stay readable.
                if (
                    Math.abs(p[0] - lx) < 170 &&
                    p[1] > ly - 110 &&
                    p[1] < ly + 70
                ) {
                    continue;
                }
                // And Ironfield's own name over the road.
                if (Math.abs(p[0] - 2480) < 190 && Math.abs(p[1] - 480) < 90) {
                    continue;
                }
                const roll = seeded(`city:model:${key}`);
                const pool =
                    roll < 0.55 - depth * 0.35
                        ? LOW
                        : roll < 0.9 - depth * 0.2
                          ? MIDDLE
                          : TALL;
                lots.push({
                    model: pool[
                        Math.floor(seeded(`city:pick:${key}`) * pool.length)
                    ],
                    x: p[0],
                    y: p[1],
                    // Fronts to the street on either side of the row.
                    turn: (col % 2 === 0 ? 1 : -1) * (Math.PI / 2),
                });
            }
        }
    }
    return lots;
}
