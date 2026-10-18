/**
 * Writes every region's outline and hub, as the map draws them, to
 * resources/data/hollowmere.json, so the server knows which region a point
 * on the map is in. Run it after changing resources/js/lib/hollowmere.ts:
 *
 *   npm run map:geometry
 */
import { writeFileSync } from 'node:fs';
import { FIELD_SHAPES, SHAPES, hub } from '../resources/js/lib/hollowmere.ts';

const round = (n: number) => Math.round(n * 10) / 10;

const regions = Object.fromEntries(
    Object.entries({ ...SHAPES, ...FIELD_SHAPES }).map(([key, shape]) => [
        key,
        {
            polygon: shape.polygon.map(([x, y]) => [round(x), round(y)]),
            hub: hub(key).map(round),
        },
    ]),
);

writeFileSync(
    new URL('../resources/data/hollowmere.json', import.meta.url),
    JSON.stringify({ regions }, null, 1) + '\n',
);

console.log(`Wrote ${Object.keys(regions).length} regions.`);
