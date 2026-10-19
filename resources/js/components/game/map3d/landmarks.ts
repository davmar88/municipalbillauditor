import * as THREE from 'three';
import type { Point } from '@/lib/hollowmere';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { CITY_BLOCKS, LAMPS, STOCKADE } from '@/components/game/map-terrain';
import { SHAPES, blob, fits, hub, scatter, seeded } from '@/lib/hollowmere';

/**
 * What the 2D map draws on Hollowmere's ground, at the same spots: straw by the
 * Big Barn's yard, hay bales, the Farmhouse garden, the windmill's hill, the
 * Duck Pond with its jetty and ducks, the hen run, the market cobbles, the
 * stables' sand ring, the quarry pit, the sheep pens and their flock, the
 * granary yard, the Paddock's trough, the Wildwood's logs, and Ironfield's two
 * barns beyond the east fence, which the 2D map draws too. As on the 2D map,
 * nothing is built on Hollowmere itself: factions raise their own buildings.
 * All in paces.
 */
export type Landmarks = { group: THREE.Group; animate: (now: number) => void };

const at = (key: string, dx: number, dy: number): Point => {
    const [cx, cy] = SHAPES[key].centre;
    return [cx + dx, cy + dy];
};

const mat = (color: string, extra: THREE.MeshLambertMaterialParameters = {}) =>
    new THREE.MeshLambertMaterial({ color, flatShading: true, ...extra });

const M = {
    wallDark: mat('#a99a83'),
    roof: mat('#4b4358'),
    wood: mat('#7a5a3d'),
    woodDark: mat('#5a4330'),
    stone: mat('#77727f'),
    stoneDark: mat('#55505d'),
    straw: mat('#c9a95a'),
    sand: mat('#8f7b55'),
    cobble: mat('#3d3a47'),
    garden: mat('#24392b'),
    water: new THREE.MeshLambertMaterial({
        color: '#235a8a',
        emissive: '#0c2a46',
    }),
    white: mat('#ece6da'),
    sheepHead: mat('#2b2622'),
    duck: mat('#e8e2cf'),
    beak: mat('#e0a03a'),
    lamp: new THREE.MeshBasicMaterial({ color: '#ffb35c' }),
    hill: mat('#28402f'),
    hillMid: mat('#2d4835'),
    hillTop: mat('#33513c'),
};

function box(
    w: number,
    h: number,
    d: number,
    m: THREE.Material,
    x: number,
    y: number,
    z: number,
    ry = 0,
) {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), m);
    mesh.position.set(x, y, z);
    mesh.rotation.y = ry;
    return mesh;
}

/** A gabled roof as a triangular prism along x. */
function gable(w: number, h: number, d: number, m: THREE.Material) {
    const shape = new THREE.Shape([
        new THREE.Vector2(-d / 2, 0),
        new THREE.Vector2(d / 2, 0),
        new THREE.Vector2(0, h),
    ]);
    const geo = new THREE.ExtrudeGeometry(shape, {
        depth: w,
        bevelEnabled: false,
    });
    geo.translate(0, 0, -w / 2);
    geo.rotateY(Math.PI / 2);
    return new THREE.Mesh(geo, m);
}

/** A house: walls, a gabled roof and a dark door, centred on (x, z), facing south. */
function house(
    w: number,
    d: number,
    h: number,
    wall: THREE.Material,
    roof: THREE.Material,
    roofH = h * 0.7,
) {
    const g = new THREE.Group();
    g.add(box(w, h, d, wall, 0, h / 2, 0));
    const r = gable(w * 1.06, roofH, d * 1.12, roof);
    r.position.y = h;
    g.add(r);
    g.add(box(w * 0.16, h * 0.55, 0.6, M.woodDark, 0, h * 0.275, d / 2 + 0.2));
    return g;
}

function placed(obj: THREE.Object3D, [x, y]: Point, angleDeg = 0) {
    obj.position.x = x;
    obj.position.z = y;
    obj.rotation.y = -THREE.MathUtils.degToRad(angleDeg);
    return obj;
}

/** A low fence round a rectangle. */
function fence(w: number, d: number, m = M.wood) {
    const g = new THREE.Group();
    const rail = 1.2;
    for (const [len, x, z, ry] of [
        [w, 0, -d / 2, 0],
        [w, 0, d / 2, 0],
        [d, -w / 2, 0, Math.PI / 2],
        [d, w / 2, 0, Math.PI / 2],
    ] as const) {
        g.add(box(len, rail, rail, m, x, 5, z, ry));
        g.add(box(len, rail, rail, m, x, 2.4, z, ry));
        const posts = Math.max(2, Math.round(len / 12));
        for (let i = 0; i <= posts; i++) {
            const t = -len / 2 + (len * i) / posts;
            g.add(
                box(1.6, 6.5, 1.6, m, x + (ry ? 0 : t), 3.25, z + (ry ? t : 0)),
            );
        }
    }
    return g;
}

/** A flat blob of ground (garden, yard, sand) just above the land. */
function patch(points: Point[], m: THREE.Material, y = 0.35) {
    const shape = new THREE.Shape(
        points.map(([x, py]) => new THREE.Vector2(x, -py)),
    );
    const geo = new THREE.ShapeGeometry(shape);
    geo.rotateX(-Math.PI / 2);
    const mesh = new THREE.Mesh(geo, m);
    mesh.position.y = y;
    return mesh;
}

function bale([x, y]: Point, r: number) {
    const g = new THREE.Group();
    const roll = new THREE.Mesh(
        new THREE.CylinderGeometry(r, r, r * 1.3, 12),
        M.straw,
    );
    roll.rotation.z = Math.PI / 2;
    roll.position.y = r;
    g.add(roll);
    g.rotation.y = seeded(`bale:${x}:${y}`) * Math.PI;
    g.position.set(x, 0, y);
    return g;
}

function sheep([x, y]: Point, i: number) {
    const g = new THREE.Group();
    const body = new THREE.Mesh(new THREE.IcosahedronGeometry(4.2, 0), M.white);
    body.scale.set(1.25, 0.9, 1);
    body.position.y = 5;
    const head = new THREE.Mesh(
        new THREE.BoxGeometry(2.2, 2.4, 2.4),
        M.sheepHead,
    );
    head.position.set(5, 5.6, 0);
    g.add(body, head);
    for (const [lx, lz] of [
        [-2.5, -1.8],
        [-2.5, 1.8],
        [2.5, -1.8],
        [2.5, 1.8],
    ]) {
        g.add(box(0.9, 2.6, 0.9, M.sheepHead, lx, 1.3, lz));
    }
    g.position.set(x, 0, y);
    g.rotation.y = seeded(`sheep:${i}`) * Math.PI * 2;
    return g;
}

export function landmarks(): Landmarks {
    const group = new THREE.Group();
    const bobbers: { obj: THREE.Object3D; phase: number }[] = [];

    // The Big Barn's yard, with straw bales either side.
    {
        const door = hub('big_barn');
        group.add(
            patch(
                blob([door[0], door[1] - 21], 30, 12, 'barn:yard', 0.05),
                M.cobble,
            ),
        );
        for (const [dx, dy, r] of [
            [-92, 6, 9],
            [-78, 24, 7],
            [84, 22, 8],
            [96, 6, 6],
        ] as const) {
            group.add(bale(at('big_barn', dx, dy), r));
        }
    }

    // Hay bales across the hayfield, off its lane.
    {
        const lane = hub('hayfield');
        for (const p of scatter(SHAPES.hayfield, 16, 'bales', 14).filter(
            ([, y]) => Math.abs(y - lane[1]) > 18,
        )) {
            group.add(bale(p, 6.5));
        }
    }

    // The Farmhouse garden.
    group.add(
        patch(
            blob(at('farmhouse', 0, -16), 118, 72, 'farmhouse:garden', 0.07),
            M.garden,
        ),
    );

    // Windmill Hill: its contour rings, lying flat as on the 2D map, so animals,
    // rings and washes on it all show.
    {
        const top = at('windmill_hill', 16, -30);
        (
            [
                [108, 74, 0.2, M.hill],
                [80, 54, 0.27, M.hillMid],
                [52, 36, 0.34, M.hillTop],
            ] as const
        ).forEach(([rx, ry, y, material], i) =>
            group.add(patch(blob(top, rx, ry, `hill:${i}`, 0.09), material, y)),
        );
    }

    // The Duck Pond: water, reeds round it, a jetty and ducks.
    {
        const pond = at('duck_pond', -16, -18);
        group.add(patch(blob(pond, 78, 48, 'pond', 0.14), M.water, 0.45));
        group.add(
            placed(box(26, 1.4, 6, M.wood, 0, 0, 0), [
                pond[0] + 71,
                pond[1] + 21,
            ]).translateY(2),
        );
        for (let i = 0; i < 6; i++) {
            const angle = seeded(`duck:${i}`) * Math.PI * 2;
            const d = 0.25 + seeded(`duck:r:${i}`) * 0.45;
            const duck = new THREE.Group();
            const body = new THREE.Mesh(
                new THREE.SphereGeometry(2.6, 8, 6),
                M.duck,
            );
            body.scale.set(1.4, 0.8, 1);
            const head = new THREE.Mesh(
                new THREE.SphereGeometry(1.3, 8, 6),
                M.duck,
            );
            head.position.set(2.8, 2, 0);
            const beak = box(1.4, 0.5, 0.8, M.beak, 4.2, 1.9, 0);
            duck.add(body, head, beak);
            duck.position.set(
                pond[0] + Math.cos(angle) * 78 * d,
                1.2,
                pond[1] + Math.sin(angle) * 48 * d,
            );
            duck.rotation.y = angle;
            bobbers.push({ obj: duck, phase: i * 1.7 });
            group.add(duck);
        }
    }

    // The hens' fenced run.
    {
        const [rx, ry] = at('henhouse', -70, -8);
        const run = fence(128, 40);
        group.add(placed(run, [rx + 64, ry + 20]));
    }

    // The cobbled Market Square.
    {
        const [x, y] = hub('market_square');
        group.add(
            patch(
                [
                    [x - 66, y - 46],
                    [x + 66, y - 46],
                    [x + 66, y + 46],
                    [x - 66, y + 46],
                ],
                M.cobble,
            ),
        );
    }

    // The stables' sand ring.
    {
        const ring = at('stables', -56, 34);
        const sand = new THREE.Mesh(new THREE.CircleGeometry(1, 28), M.sand);
        sand.rotation.x = -Math.PI / 2;
        sand.scale.set(42, 27, 1);
        sand.position.set(ring[0], 0.4, ring[1]);
        group.add(sand);
    }

    // The quarry pit, stepping down to water.
    {
        const pit = at('quarry', 24, -22);
        [
            [70, 0.3, M.stoneDark],
            [52, 0.36, M.stone],
            [34, 0.42, M.stoneDark],
            [18, 0.48, M.water],
        ].forEach(([r, yy, m]) => {
            const ring = new THREE.Mesh(
                new THREE.CircleGeometry(r as number, 22),
                m as THREE.Material,
            );
            ring.rotation.x = -Math.PI / 2;
            ring.scale.set(1, 0.7, 1);
            ring.position.set(pit[0], yy as number, pit[1]);
            group.add(ring);
        });
    }

    // The sheep pens and the flock.
    {
        const s = SHAPES.sheepfold;
        const pens = [
            { centre: at('sheepfold', -38, -34), w: 88, h: 52, angle: 6 },
            { centre: at('sheepfold', 52, -52), w: 58, h: 40, angle: -8 },
        ];
        pens.forEach(({ centre, w, h, angle }) =>
            group.add(placed(fence(w, h), centre, angle)),
        );
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
                (p) =>
                    Math.hypot(
                        p[0] - hub('sheepfold')[0],
                        p[1] - hub('sheepfold')[1],
                    ) > 30,
            ),
        ];
        flock.forEach((p, i) => group.add(sheep(p, i)));
    }

    // The granary's yard.
    group.add(
        patch(
            blob(at('granary', -6, -32), 104, 40, 'granary:yard', 0.1),
            M.cobble,
        ),
    );

    // The Paddock's water trough.
    {
        const [x, y] = at('paddock', -150, -8);
        group.add(box(34, 5, 9, M.wood, x + 17, 2.5, y + 4.5));
        const water = box(31, 0.5, 6, M.water, x + 17, 5, y + 4.5);
        group.add(water);
    }

    // Irongate past the east fence: the town wall, tenements in the Rookery,
    // sheds and crates in the Iron Yards, the Garrison's stockade and
    // watchtower, and lamps lit at the gates. Out here, men build.
    {
        for (const [key, blocks] of Object.entries(CITY_BLOCKS)) {
            const wall = SHAPES[key].polygon;
            for (let i = 0; i < wall.length; i++) {
                const [ax, ay] = wall[i];
                const [bx, by] = wall[(i + 1) % wall.length];
                const length = Math.hypot(bx - ax, by - ay);
                // The gate onto Gate Road stays open.
                if (key === 'irongate_yards' && i === wall.length - 1) {
                    continue;
                }
                const stones = box(
                    length,
                    9,
                    5,
                    M.stoneDark,
                    (ax + bx) / 2,
                    4.5,
                    (ay + by) / 2,
                );
                stones.rotation.y = -Math.atan2(by - ay, bx - ax);
                group.add(stones);
            }
            blocks.forEach(([x, y, w, d, angle], i) => {
                const height =
                    key === 'irongate_rookery' ? 26 + (i % 3) * 8 : 20;
                group.add(
                    placed(
                        house(
                            w,
                            d,
                            height,
                            i % 2 ? M.wallDark : M.stone,
                            M.roof,
                            10,
                        ),
                        [x, y],
                        angle,
                    ),
                );
            });
        }
        const { x, y, width, height } = STOCKADE;
        const posts = 34;
        for (let i = 0; i < posts; i++) {
            const t = i / posts;
            const side = Math.floor(t * 4);
            const u = t * 4 - side;
            const [px, py] = [
                [x + u * width, y],
                [x + width, y + u * height],
                [x + width - u * width, y + height],
                [x, y + height - u * height],
            ][side];
            group.add(box(3, 14, 3, M.woodDark, px, 7, py));
        }
        const tower = new THREE.Group();
        for (const [dx, dz] of [
            [-6, -6],
            [6, -6],
            [-6, 6],
            [6, 6],
        ]) {
            tower.add(box(2, 36, 2, M.wood, dx, 18, dz));
        }
        tower.add(box(18, 3, 18, M.wood, 0, 36, 0));
        const cap = gable(20, 8, 20, M.roof);
        cap.position.y = 37;
        tower.add(cap);
        group.add(placed(tower, [x + width, y]));
        for (const [cx, cy] of [
            [1752, 458],
            [1764, 470],
            [1742, 474],
            [1836, 462],
            [1848, 474],
            [1690, 566],
        ]) {
            group.add(
                box(10, 10, 10, M.wood, cx, 5, cy, seeded(`crate:${cx}`) * 0.6),
            );
        }
        for (const [lx, ly] of LAMPS) {
            group.add(box(1.5, 16, 1.5, M.woodDark, lx, 8, ly));
            const lamp = new THREE.Mesh(
                new THREE.SphereGeometry(2.6, 8, 6),
                M.lamp,
            );
            lamp.position.set(lx, 17, ly);
            group.add(lamp);
        }
    }

    // Logs in the Wildwood clearing.
    {
        const c = hub('wildwood');
        for (let i = 0; i < 3; i++) {
            const log = new THREE.Mesh(
                new THREE.CylinderGeometry(2, 2, 18, 7),
                M.woodDark,
            );
            log.rotation.z = Math.PI / 2;
            log.position.set(
                c[0] + 16,
                2 + (i === 2 ? 3.6 : 0),
                c[1] - 18 + i * 4.5 - (i === 2 ? 2.2 : 0),
            );
            group.add(log);
        }
    }

    // Everything that doesn't move becomes one shape per material: a handful of
    // draws instead of hundreds, which phones notice.
    const moving = new Set<THREE.Object3D>(bobbers.map((b) => b.obj));
    const still: THREE.Mesh[] = [];
    group.updateMatrixWorld(true);
    group.traverse((o) => {
        const mesh = o as THREE.Mesh;
        let up: THREE.Object3D | null = mesh;
        while (up && !moving.has(up)) {
            up = up.parent;
        }
        if (mesh.isMesh && up === null) {
            still.push(mesh);
        }
    });
    const byMaterial = new Map<THREE.Material, THREE.BufferGeometry[]>();
    for (const mesh of still) {
        let geo = mesh.geometry.clone().applyMatrix4(mesh.matrixWorld);
        if (geo.index) {
            geo = geo.toNonIndexed();
        }
        // Only shape and shading are needed; every part must carry the same.
        for (const name of Object.keys(geo.attributes)) {
            if (name !== 'position' && name !== 'normal') {
                geo.deleteAttribute(name);
            }
        }
        geo.clearGroups();
        const material = mesh.material as THREE.Material;
        byMaterial.set(material, [...(byMaterial.get(material) ?? []), geo]);
        mesh.geometry.dispose();
        mesh.removeFromParent();
    }
    for (const [material, parts] of byMaterial) {
        const merged = mergeGeometries(parts, false);
        parts.forEach((part) => part.dispose());
        if (merged) {
            group.add(new THREE.Mesh(merged, material));
        }
    }

    return {
        group,
        animate: (now: number) => {
            for (const { obj, phase } of bobbers) {
                obj.position.y = 1.2 + Math.sin(now / 600 + phase) * 0.4;
                obj.rotation.y += 0.0008;
            }
        },
    };
}

/** The orchard's rows of trees, at the 2D map's own spots. */
export function orchardTrees(): Point[] {
    const s = SHAPES.orchard;
    const path = hub('orchard');
    const out: Point[] = [];
    for (let row = 0; row < 12; row++) {
        for (let col = 0; col < 12; col++) {
            const p: Point = [
                s.box.x + 12 + col * 26 + (row % 2) * 13,
                s.box.y + 14 + row * 24,
            ];
            if (
                Math.abs(p[1] - path[1]) > 15 &&
                !(Math.abs(p[0] - path[0]) < 14 && p[1] > path[1]) &&
                fits(p, s.polygon, 12)
            ) {
                out.push(p);
            }
        }
    }
    return out;
}
