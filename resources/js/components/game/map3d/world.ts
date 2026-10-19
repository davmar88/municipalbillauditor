import * as THREE from 'three';
import { Line2 } from 'three/examples/jsm/lines/Line2.js';
import { LineGeometry } from 'three/examples/jsm/lines/LineGeometry.js';
import { LineMaterial } from 'three/examples/jsm/lines/LineMaterial.js';
import { CSS2DObject } from 'three/examples/jsm/renderers/CSS2DRenderer.js';
import type { GateResult, Size } from '@/components/game/world-map';
import {
    BUILDING_GLYPH,
    FLAG,
    HALO,
    LABEL,
    SPOTS,
    VIOLET,
    labelPixels,
    labelPoint,
    shapeFor,
    subtitle,
    walkPoint,
} from '@/components/game/world-map';
import { speciesEmoji } from '@/lib/game';
import type { Point, RegionShape } from '@/lib/hollowmere';
import {
    BROOK,
    FARMS,
    FRONTIER_KIND,
    FIELD_SHAPES,
    FIELDS,
    anchor,
    hub,
    plot,
    scatter,
    scrubPoints,
    seeded,
} from '@/lib/hollowmere';
import type {
    BuildingKindValue,
    FactionRef,
    Folk,
    MapAnimal,
    MapHome,
    MapMe,
    MapRegion,
    Species,
    Worn,
} from '@/types/game';
import { person as makePerson } from './folk';
import { landmarks, orchardTrees } from './landmarks';
import type { Actor } from './models';
import { makeActor } from './models';

/** What the 3D map shows, as the 2D map gets it. */
export type WorldData = {
    regions: MapRegion[];
    house: FactionRef | null;
    gateNight: boolean;
    gateResult: GateResult;
    wanted: number[];
    selectedId: number | null;
    me: MapMe | null;
    species: Species | null;
    wearing: Worn[];
    /** Irongate's people, standing in their districts. */
    folk: Folk[];
    /** Every animal's home. */
    homes: MapHome[];
};

// ---- the look of the land -------------------------------------------------------------

/** What kind of ground each of Hollowmere's regions is. */
const KIND: Record<string, string> = {
    wildwood: 'woods',
    orchard: 'orchard',
    big_barn: 'yard',
    hayfield: 'corn',
    quarry: 'rock',
    sheepfold: 'meadow',
    granary: 'yard',
    farmhouse: 'meadow',
    windmill_hill: 'meadow',
    ironfield_gate: 'yard',
    duck_pond: 'wet',
    henhouse: 'yard',
    market_square: 'yard',
    stables: 'meadow',
    old_well: 'meadow',
    paddock: 'meadow',
    irongate_rookery: 'street',
    irongate_yards: 'street',
    irongate_garrison: 'street',
    irongate_smithy: 'street',
    irongate_tavern: 'street',
    irongate_gallows: 'street',
};
/** Wild land round the farms, by the last word of its key. */
const WILD: Record<string, string> = {
    heath: 'heath',
    hives: 'meadow',
    hollow: 'heath',
    reeds: 'wet',
    marsh: 'wet',
    meadow: 'meadow',
    copse: 'woods',
    forge: 'rock',
    pits: 'rock',
    ridge: 'rock',
    wood: 'woods',
    spring: 'wet',
    downs: 'heath',
    moor: 'heath',
    brake: 'woods',
    glen: 'woods',
    leas: 'meadow',
    mill: 'meadow',
    fold: 'meadow',
    fen: 'wet',
    rise: 'heath',
    crag: 'rock',
    scar: 'rock',
    mine: 'rock',
    moss: 'wet',
    bog: 'wet',
    banks: 'wet',
    carr: 'woods',
    dell: 'woods',
    gardens: 'meadow',
    weald: 'woods',
    workings: 'rock',
    tor: 'rock',
    slag: 'rock',
};
/** What each farm grows, as ValleyBuilder has it. */
const FARM_CROP: Record<
    string,
    | 'honey'
    | 'herbs'
    | 'iron'
    | 'gold'
    | 'copper'
    | 'pears'
    | 'carrots'
    | 'berries'
    | 'pumpkins'
> = {
    thornbury: 'honey',
    brookside: 'herbs',
    willowby: 'iron',
    ashcombe: 'iron',
    fennick: 'honey',
    marlow: 'herbs',
    hartley: 'carrots',
    northfold: 'pears',
    coldharbour: 'gold',
    rushmere: 'berries',
    southmere: 'pumpkins',
    copperhill: 'copper',
};

/** A region's ground: a farm, a field a farm has cleared, wild land, or one of Hollowmere's own. */
/** How each kind of frontier land looks in 3D. */
const FRONTIER_LOOK: Record<string, string> = {
    woods: 'woods',
    ponds: 'wet',
    scrub: 'heath',
    stone: 'rock',
    grass: 'meadow',
    deposit: 'rock',
};

function kindOf(
    key: string,
    isRegion: boolean,
): { kind: string; wild: boolean } {
    if (FARMS[key]) {
        return { kind: 'farm', wild: false };
    }
    if (FRONTIER_KIND[key]) {
        return { kind: FRONTIER_LOOK[FRONTIER_KIND[key]], wild: false };
    }
    if (FIELDS[key]) {
        return isRegion
            ? { kind: 'farm', wild: false }
            : {
                  kind: WILD[key.split('_').pop() ?? ''] ?? 'meadow',
                  wild: true,
              };
    }
    return { kind: KIND[key] ?? 'meadow', wild: false };
}

const LOOK: Record<string, { colour: string; stripe?: string }> = {
    meadow: { colour: '#2c4636' },
    woods: { colour: '#1f3a2a' },
    orchard: { colour: '#29402f' },
    corn: { colour: '#4e4a2b', stripe: '#5f5a33' },
    farm: { colour: '#2c452f', stripe: '#38573a' },
    yard: { colour: '#36323f' },
    rock: { colour: '#3f3c47' },
    wet: { colour: '#22403c' },
    heath: { colour: '#433a4b' },
    street: { colour: '#2e2a35' },
};

function stripes(colour: string, stripe: string): THREE.CanvasTexture {
    const c = document.createElement('canvas');
    c.width = 8;
    c.height = 32;
    const g = c.getContext('2d') as CanvasRenderingContext2D;
    g.fillStyle = colour;
    g.fillRect(0, 0, 8, 32);
    g.fillStyle = stripe;
    g.fillRect(0, 0, 8, 12);
    const t = new THREE.CanvasTexture(c);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.colorSpace = THREE.SRGBColorSpace;
    return t;
}

/** A flat shape on the ground from a map polygon (map y is three's z). */
function groundShape(polygon: Point[]): THREE.ShapeGeometry {
    const shape = new THREE.Shape(
        polygon.map(([x, y]) => new THREE.Vector2(x, -y)),
    );
    const geo = new THREE.ShapeGeometry(shape);
    geo.rotateX(-Math.PI / 2);
    return geo;
}

/** A polygon pulled in towards its middle, so a border sits inside its region. */
function inset(polygon: Point[], by: number): Point[] {
    const cx = polygon.reduce((a, p) => a + p[0], 0) / polygon.length;
    const cy = polygon.reduce((a, p) => a + p[1], 0) / polygon.length;
    return polygon.map(([x, y]) => {
        const d = Math.hypot(x - cx, y - cy) || 1;
        return [x - ((x - cx) / d) * by, y - ((y - cy) / d) * by];
    });
}

function outline(polygon: Point[], height: number): number[] {
    const out: number[] = [];
    for (const [x, y] of [...polygon, polygon[0]]) {
        out.push(x, height, y);
    }
    return out;
}

function div(
    className: string,
    style: Record<string, string> = {},
    text = '',
): HTMLDivElement {
    const d = document.createElement('div');
    d.className = className;
    Object.assign(d.style, style);
    d.textContent = text;
    return d;
}

const motion =
    typeof window !== 'undefined'
        ? (window.matchMedia?.('(prefers-reduced-motion: reduce)') ?? null)
        : null;
const reducedMotion = () => motion?.matches === true;

/** Move a one-segment line's ends without new buffers (it changes every frame while you walk). */
function setSegment(
    line: Line2,
    a: [number, number, number],
    b: [number, number, number],
) {
    const geo = line.geometry;
    const ends = geo.getAttribute(
        'instanceStart',
    ) as THREE.InterleavedBufferAttribute;
    (ends.data.array as Float32Array).set([...a, ...b]);
    ends.data.needsUpdate = true;
    const dist = geo.getAttribute('instanceDistanceStart') as
        | THREE.InterleavedBufferAttribute
        | undefined;
    if (dist) {
        const array = dist.data.array as Float32Array;
        array[0] = 0;
        array[1] = Math.hypot(b[0] - a[0], b[1] - a[1], b[2] - a[2]);
        dist.data.needsUpdate = true;
    }
}

const LABEL_SHADOW = `0 0 3px ${HALO}, 0 0 6px ${HALO}, 0 1px 2px ${HALO}`;

// ---- the world ---------------------------------------------------------------------------------------

type CrowdEntry = {
    id: number;
    species: Species;
    wearing: Worn[];
    key: string;
    actor: Actor | null;
    loading: boolean;
    disc: CSS2DObject;
    ring: THREE.Mesh;
    badge: CSS2DObject | null;
    /** Whether it is near enough the middle of the view for a real 3D model. */
    cast: boolean;
    /** The hub it stands round, and its spot there in screen pixels. */
    centre: Point;
    spot: Point;
    seed: number;
};

/**
 * Everything on the 3D map: the land, who holds it, the labels, the buildings,
 * the raids on their way and every animal. The React side owns the camera and
 * the gestures; this draws.
 */
export class World {
    readonly scene = new THREE.Scene();
    private readonly lines = new Set<LineMaterial>();
    private readonly overlay = new THREE.Group();
    private readonly marks = new THREE.Group();
    private readonly people = new THREE.Group();
    /** The selected region's outline and the hover light, redrawn on their own. */
    private readonly picked = new THREE.Group();
    /** Raids on their way, redrawn on their own. */
    private readonly raids = new THREE.Group();
    /** Irongate's people. */
    private readonly town = new THREE.Group();
    /** Every animal's home. */
    private readonly dwellings = new THREE.Group();
    private regionLabels = new Map<
        number,
        {
            obj: CSS2DObject;
            name: HTMLDivElement;
            sub: HTMLDivElement;
            width: number;
            text: string;
        }
    >();
    private fixedLabels: {
        obj: CSS2DObject;
        el: HTMLDivElement;
        minK?: number;
    }[] = [];
    private crowd = new Map<number, CrowdEntry>();
    private extras: CSS2DObject[] = [];
    /** One ring shape for every animal, and one material per ring colour. */
    private readonly ringShape = new THREE.RingGeometry(0.34, 0.46, 24);
    private readonly ringColours = new Map<string, THREE.MeshBasicMaterial>();
    private pulsing: (THREE.MeshBasicMaterial | LineMaterial)[] = [];
    private marching: LineMaterial[] = [];
    private heads: THREE.Mesh[] = [];
    private buildings: { group: THREE.Group; at: Point }[] = [];
    private flag: THREE.Group | null = null;
    private terrainBuilt = false;
    private readonly terrain = new THREE.Group();
    /** The wild patches farms have cleared, as the ground was last built. */
    private fields: string | null = null;
    /** Something moved or changed since the last frame, so draw the labels too. */
    private changed = true;
    private marks3d: ReturnType<typeof landmarks> | null = null;
    private data: WorldData | null = null;
    private hovered: number | null = null;
    private hoverMesh: THREE.Mesh | null = null;
    private k = 0.6;
    private size: Size = { width: 1, height: 1 };
    private actorBudget: number;
    // Your animal.
    private me: {
        actor: Actor | null;
        key: string;
        shown: Point | null;
        ring: THREE.Mesh;
        ping: THREE.Mesh;
        tag: CSS2DObject;
        disc: CSS2DObject;
        line: Line2;
        lineMat: LineMaterial;
        end: THREE.Group;
        walking: boolean;
        playing: Actor | null;
    };
    private disposed = false;

    constructor(coarse: boolean) {
        this.actorBudget = coarse ? 24 : 60;
        const s = this.scene;
        s.background = new THREE.Color('#13151a');
        s.fog = new THREE.Fog('#13151a', 2000, 9000);
        s.add(new THREE.HemisphereLight('#c9c2ff', '#1d1a24', 1.5));
        const sun = new THREE.DirectionalLight('#fff4e6', 1.8);
        sun.position.set(-600, 1400, 900);
        s.add(sun);
        const ground = new THREE.Mesh(
            new THREE.PlaneGeometry(30000, 30000),
            new THREE.MeshLambertMaterial({ color: '#18211d' }),
        );
        ground.rotation.x = -Math.PI / 2;
        ground.position.y = -0.6;
        s.add(ground);
        s.add(
            this.terrain,
            this.overlay,
            this.picked,
            this.marks,
            this.raids,
            this.town,
            this.dwellings,
            this.people,
        );

        // Your animal's markers, made once.
        const ring = new THREE.Mesh(
            new THREE.RingGeometry(0.42, 0.55, 32),
            new THREE.MeshBasicMaterial({
                color: VIOLET,
                transparent: true,
                opacity: 0.95,
                depthWrite: false,
            }),
        );
        ring.rotation.x = -Math.PI / 2;
        const ping = new THREE.Mesh(
            new THREE.RingGeometry(0.45, 0.6, 32),
            new THREE.MeshBasicMaterial({
                color: VIOLET,
                transparent: true,
                opacity: 0.6,
                depthWrite: false,
            }),
        );
        ping.rotation.x = -Math.PI / 2;
        const tagEl = div(
            '',
            {
                background: VIOLET,
                color: '#16171d',
                font: '800 10px system-ui, sans-serif',
                padding: '1px 6px',
                borderRadius: '9px',
                letterSpacing: '0.08em',
                pointerEvents: 'none',
                boxShadow: `0 1px 3px ${HALO}`,
            },
            'YOU',
        );
        const tag = new CSS2DObject(tagEl);
        tag.center.set(0.5, -0.6);
        tag.renderOrder = 6;
        const discEl = div('', {
            fontSize: '19px',
            pointerEvents: 'none',
            filter: `drop-shadow(0 1px 2px ${HALO})`,
        });
        const disc = new CSS2DObject(discEl);
        disc.renderOrder = 5;
        const lineMat = this.lineMaterial(VIOLET, 3, {
            dashed: true,
            opacity: 0.9,
        });
        const lineGeo = new LineGeometry();
        lineGeo.setPositions([0, 0, 0, 1, 0, 0]);
        const line = new Line2(lineGeo, lineMat);
        line.computeLineDistances();
        line.frustumCulled = false;
        line.visible = false;
        const end = new THREE.Group();
        const endRing = new THREE.Mesh(
            new THREE.RingGeometry(0.5, 0.75, 24),
            new THREE.MeshBasicMaterial({ color: VIOLET, depthWrite: false }),
        );
        endRing.rotation.x = -Math.PI / 2;
        const endDot = new THREE.Mesh(
            new THREE.CircleGeometry(0.22, 16),
            new THREE.MeshBasicMaterial({
                color: '#16171d',
                depthWrite: false,
            }),
        );
        endDot.rotation.x = -Math.PI / 2;
        endDot.position.y = 0.01;
        end.add(endRing, endDot);
        end.visible = false;
        s.add(ring, ping, tag, disc, line, end);
        this.me = {
            actor: null,
            key: '',
            shown: null,
            ring,
            ping,
            tag,
            disc,
            line,
            lineMat,
            end,
            walking: false,
            playing: null,
        };
    }

    private lineMaterial(
        colour: string,
        width: number,
        opts: {
            dashed?: boolean;
            opacity?: number;
            vertexColors?: boolean;
            onTop?: boolean;
        } = {},
    ) {
        const m = new LineMaterial({
            color: opts.vertexColors
                ? 0xffffff
                : new THREE.Color(colour).getHex(),
            linewidth: width,
            worldUnits: false,
            dashed: opts.dashed ?? false,
            dashSize: 14,
            gapSize: 10,
            transparent: true,
            opacity: opts.opacity ?? 1,
            vertexColors: opts.vertexColors ?? false,
            depthTest: !opts.onTop,
            depthWrite: false,
        });
        m.resolution.set(this.size.width, this.size.height);
        this.lines.add(m);
        return m;
    }

    resize(size: Size) {
        this.size = size;
        this.lines.forEach((m) => m.resolution.set(size.width, size.height));
    }

    // ---- static land -------------------------------------------------------------------------------

    /** The ground of every region and wild patch, with what grows on it. */
    private buildTerrain(regions: MapRegion[]) {
        this.clearGroup(this.terrain);
        const keys = new Set(regions.map((r) => r.key));
        const tiles: {
            key: string;
            shape: RegionShape;
            kind: string;
            wild: boolean;
        }[] = regions.map((r) => ({
            key: r.key,
            shape: shapeFor(r),
            ...kindOf(r.key, true),
        }));
        // Wild land round the farms isn't a region yet, but it is still land.
        for (const [key, shape] of Object.entries(FIELD_SHAPES)) {
            if (!keys.has(key)) {
                tiles.push({ key, shape, ...kindOf(key, false) });
            }
        }
        const trees: [number, number, number][] = [];
        const rocks: Point[] = [];
        const ore: Point[] = [];
        const hives: Point[] = [];
        const herbs: Point[] = [];
        const reeds: Point[] = [];
        const round: [number, number, number][] = orchardTrees().map(
            ([x, y]) => [x, y, 1],
        );
        for (const { key, shape, kind, wild } of tiles) {
            const look = LOOK[kind];
            let material: THREE.Material;
            const colour = new THREE.Color(look.colour).multiplyScalar(
                wild ? 0.78 : 1,
            );
            if (look.stripe && !wild) {
                const map = stripes(look.colour, look.stripe);
                map.repeat.set(1 / 26, 1 / 26);
                map.rotation = seeded(`${key}:rows`) * Math.PI;
                material = new THREE.MeshLambertMaterial({ map });
            } else {
                material = new THREE.MeshLambertMaterial({ color: colour });
            }
            const tile = new THREE.Mesh(groundShape(shape.polygon), material);
            this.terrain.add(tile);
            const area = shape.box.width * shape.box.height;
            if (key === 'wildwood') {
                // The 2D map's own trees, round a clearing at the hub.
                const clearing = hub('wildwood');
                for (const [x, y] of scatter(shape, 110, 'trees:wildwood', 4)) {
                    if (Math.hypot(x - clearing[0], y - clearing[1]) >= 40) {
                        trees.push([x, y, 0.85]);
                    }
                }
            } else if (kind === 'woods') {
                const centre = hub(key);
                for (const [x, y] of scatter(
                    shape,
                    Math.min(120, Math.round(area / (wild ? 1600 : 1300))),
                    `${key}:trees`,
                    10,
                )) {
                    if (Math.hypot(x - centre[0], y - centre[1]) >= 34) {
                        trees.push([x, y, 1]);
                    }
                }
            } else if (kind === 'meadow' || kind === 'heath') {
                for (const [x, y] of scatter(
                    shape,
                    Math.min(10, Math.round(area / 9000)),
                    `${key}:bushes`,
                    20,
                )) {
                    trees.push([x, y, 0.6]);
                }
            } else if (kind === 'rock') {
                const centre = hub(key);
                const pit: Point =
                    key === 'quarry'
                        ? [shape.centre[0] + 24, shape.centre[1] - 22]
                        : centre;
                rocks.push(
                    ...scatter(
                        shape,
                        Math.min(60, Math.round(area / 2500)),
                        `${key}:rocks`,
                        8,
                    ).filter(
                        ([x, y]) =>
                            Math.hypot(x - pit[0], y - pit[1]) >
                                (key === 'quarry' ? 82 : 30) &&
                            Math.hypot(x - centre[0], y - centre[1]) > 30,
                    ),
                );
            } else if (kind === 'wet') {
                // The Duck Pond has its own pond; other wet land gets a couple of small ones.
                for (const [x, y] of key === 'duck_pond'
                    ? []
                    : scatter(shape, 2, `${key}:ponds`, 60)) {
                    const pond = new THREE.Mesh(
                        new THREE.CircleGeometry(
                            30 + seeded(`${key}:${x}`) * 25,
                            20,
                        ),
                        new THREE.MeshLambertMaterial({
                            color: '#1f4f7a',
                            emissive: '#0b2540',
                        }),
                    );
                    pond.rotation.x = -Math.PI / 2;
                    pond.scale.set(1, 0.65, 1);
                    pond.position.set(x, 0.2, y);
                    this.terrain.add(pond);
                }
                reeds.push(
                    ...scatter(
                        shape,
                        Math.min(50, Math.round(area / 2200)),
                        `${key}:reeds`,
                        8,
                    ),
                );
            } else if (kind === 'farm') {
                const crop = FARM_CROP[FIELDS[key]?.farm ?? key] ?? 'herbs';
                const list =
                    crop === 'honey'
                        ? hives
                        : ['iron', 'gold', 'copper'].includes(crop)
                          ? ore
                          : herbs;
                list.push(
                    ...scatter(
                        shape,
                        Math.min(
                            crop === 'honey' ? 22 : 60,
                            Math.round(area / (crop === 'honey' ? 6000 : 2400)),
                        ),
                        `${key}:crop`,
                        14,
                    ),
                );
            }
        }

        const instanced = (
            points: [number, number, number][],
            geo: THREE.BufferGeometry,
            mat: THREE.Material,
            size: number,
            lift: number,
            tones?: string[],
        ) => {
            if (points.length === 0) {
                return;
            }
            const mesh = new THREE.InstancedMesh(geo, mat, points.length);
            const m = new THREE.Matrix4();
            const q = new THREE.Quaternion();
            const scale = new THREE.Vector3();
            const p = new THREE.Vector3();
            const c = new THREE.Color();
            points.forEach(([x, y, s], i) => {
                const r = seeded(`${x.toFixed(1)},${y.toFixed(1)}`);
                const k = size * s * (0.75 + r * 0.5);
                q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), r * Math.PI * 2);
                scale.set(k, k * (0.85 + r * 0.4), k);
                p.set(x, lift * k, y);
                mesh.setMatrixAt(i, m.compose(p, q, scale));
                if (tones) {
                    mesh.setColorAt(
                        i,
                        c.set(
                            tones[Math.floor(r * tones.length) % tones.length],
                        ),
                    );
                }
            });
            this.terrain.add(mesh);
        };
        const flat = (pts: Point[]) =>
            pts.map(([x, y]) => [x, y, 1] as [number, number, number]);
        instanced(
            trees,
            new THREE.ConeGeometry(0.32, 1, 6),
            new THREE.MeshLambertMaterial({ flatShading: true }),
            26,
            0.62,
            ['#2f5a3c', '#3a6b47', '#27503a', '#355f41'],
        );
        // The open scrub between the regions: low round bushes out to the valley's edge.
        instanced(
            flat(scrubPoints()),
            new THREE.IcosahedronGeometry(0.5, 0),
            new THREE.MeshLambertMaterial({ flatShading: true }),
            24,
            0.3,
            ['#4d5e2f', '#5a6b36', '#43532a', '#61703a'],
        );
        // The orchard's fruit trees: round, with a little blossom pink among the green.
        instanced(
            round,
            new THREE.IcosahedronGeometry(0.5, 0),
            new THREE.MeshLambertMaterial({ flatShading: true }),
            17,
            0.75,
            ['#3a6b47', '#355f41', '#6b5a86'],
        );
        instanced(
            round,
            new THREE.CylinderGeometry(0.06, 0.08, 0.5, 5),
            new THREE.MeshLambertMaterial({ color: '#4a3a2c' }),
            17,
            0.25,
        );
        instanced(
            trees,
            new THREE.CylinderGeometry(0.05, 0.07, 0.3, 5),
            new THREE.MeshLambertMaterial({ color: '#4a3a2c' }),
            26,
            0.15,
        );
        instanced(
            flat(rocks),
            new THREE.DodecahedronGeometry(1, 0),
            new THREE.MeshLambertMaterial({ flatShading: true }),
            7,
            0.35,
            ['#6d6879', '#5c5866', '#7b748b'],
        );
        instanced(
            flat(ore),
            new THREE.DodecahedronGeometry(1, 0),
            new THREE.MeshLambertMaterial({ flatShading: true }),
            5.5,
            0.35,
            ['#7a4a33', '#8c5a3c', '#5e3a2a'],
        );
        instanced(
            flat(hives),
            new THREE.BoxGeometry(1, 1.1, 1),
            new THREE.MeshLambertMaterial({
                color: '#c99a3b',
                flatShading: true,
            }),
            8,
            0.55,
        );
        instanced(
            flat(herbs),
            new THREE.IcosahedronGeometry(1, 0),
            new THREE.MeshLambertMaterial({ flatShading: true }),
            4.5,
            0.6,
            ['#4f8a4a', '#6aa35a', '#8a6fb5'],
        );
        instanced(
            flat(reeds),
            new THREE.ConeGeometry(0.15, 1, 4),
            new THREE.MeshLambertMaterial({ flatShading: true }),
            11,
            0.5,
            ['#5d6b3a', '#6f7c45'],
        );
    }

    /** What never changes: Hollow Brook, the ground details and the area names. */
    private buildStatic() {
        // Hollow Brook, a ribbon of water.
        const curve = new THREE.CatmullRomCurve3(
            BROOK.map(([x, y]) => new THREE.Vector3(x, 0.3, y)),
        );
        const pts = curve.getSpacedPoints(300);
        const pos: number[] = [];
        for (let i = 0; i < pts.length - 1; i++) {
            const a = pts[i];
            const b = pts[i + 1];
            const side = new THREE.Vector3(b.z - a.z, 0, a.x - b.x)
                .normalize()
                .multiplyScalar(9);
            pos.push(
                a.x - side.x,
                0.3,
                a.z - side.z,
                a.x + side.x,
                0.3,
                a.z + side.z,
                b.x - side.x,
                0.3,
                b.z - side.z,
            );
            pos.push(
                a.x + side.x,
                0.3,
                a.z + side.z,
                b.x + side.x,
                0.3,
                b.z + side.z,
                b.x - side.x,
                0.3,
                b.z - side.z,
            );
        }
        const brook = new THREE.BufferGeometry();
        brook.setAttribute(
            'position',
            new THREE.Float32BufferAttribute(pos, 3),
        );
        brook.computeVertexNormals();
        this.scene.add(
            new THREE.Mesh(
                brook,
                new THREE.MeshLambertMaterial({
                    color: '#2b5b88',
                    emissive: '#0f2c48',
                    side: THREE.DoubleSide,
                }),
            ),
        );

        // What the 2D map draws on the ground: bales, pens, the pond, the hill.
        this.marks3d = landmarks();
        this.scene.add(this.marks3d.group);

        // The fixed area labels.
        const fixed = (
            text: string,
            at: Point,
            style: Record<string, string>,
            minK?: number,
        ) => {
            const el = div(
                '',
                {
                    color: LABEL,
                    textShadow: LABEL_SHADOW,
                    font: '800 12px system-ui, sans-serif',
                    letterSpacing: '0.18em',
                    pointerEvents: 'none',
                    whiteSpace: 'nowrap',
                    ...style,
                },
                text,
            );
            const obj = new CSS2DObject(el);
            obj.position.set(at[0], 4, at[1]);
            this.scene.add(obj);
            this.fixedLabels.push({ obj, el, minK });
        };
        fixed('THE RIDGE', [800, -570], {});
        fixed('IRONFIELD', [2480, 480], { color: '#f5a27a' });
        fixed(
            'Hollow Brook',
            BROOK[2],
            {
                font: 'italic 600 11px system-ui, sans-serif',
                letterSpacing: '0.04em',
                color: '#9cc3e6',
            },
            0.5,
        );
    }

    // ---- what changes with the game ---------------------------------------------------------------------

    update(data: WorldData) {
        if (this.disposed) {
            return;
        }
        if (!this.terrainBuilt) {
            this.buildStatic();
            this.terrainBuilt = true;
        }
        // A farm that grows into a wild patch clears it into farmland.
        const fields = data.regions
            .filter((r) => FIELDS[r.key])
            .map((r) => r.key)
            .sort()
            .join();
        if (fields !== this.fields) {
            this.fields = fields;
            this.buildTerrain(data.regions);
        }
        const was = this.data;
        this.data = data;
        const land = !was || was.regions !== data.regions;
        if (land || was.house !== data.house) {
            this.buildOverlay();
        }
        if (land || was.selectedId !== data.selectedId) {
            this.buildSelection();
        }
        if (
            land ||
            was.house !== data.house ||
            was.gateNight !== data.gateNight ||
            was.gateResult !== data.gateResult
        ) {
            this.buildLabels();
        }
        if (land) {
            this.buildBuildings();
        }
        if (land || was.gateNight !== data.gateNight) {
            this.buildMarches();
        }
        if (land || was.wanted.join() !== data.wanted.join()) {
            this.syncCrowd();
        }
        if (!was || was.folk !== data.folk) {
            this.syncFolk();
        }
        if (!was || was.homes !== data.homes) {
            this.buildHomes();
        }
        this.syncMe();
        this.changed = true;
    }

    private clearGroup(group: THREE.Group) {
        for (const child of group.children.slice()) {
            group.remove(child);
            child.traverse((o) => {
                const mesh = o as THREE.Mesh;
                if (o instanceof THREE.InstancedMesh) {
                    o.dispose();
                }
                mesh.geometry?.dispose();
                const mats = Array.isArray(mesh.material)
                    ? mesh.material
                    : mesh.material
                      ? [mesh.material]
                      : [];
                mats.forEach((m) => {
                    if (m instanceof LineMaterial) {
                        this.lines.delete(m);
                    }
                    (m as THREE.MeshLambertMaterial).map?.dispose();
                    m.dispose();
                });
                if (o instanceof CSS2DObject) {
                    o.element.remove();
                }
            });
        }
    }

    private buildOverlay() {
        const data = this.data as WorldData;
        this.clearGroup(this.overlay);
        this.pulsing = [];
        const houseId = data.house?.id ?? null;
        for (const region of data.regions) {
            const shape = shapeFor(region);
            const wash = (colour: string, opacity: number) => {
                const mat = new THREE.MeshBasicMaterial({
                    color: colour,
                    transparent: true,
                    opacity,
                    depthWrite: false,
                });
                const mesh = new THREE.Mesh(groundShape(shape.polygon), mat);
                mesh.position.y = 0.5;
                mesh.renderOrder = 1;
                this.overlay.add(mesh);
                return mat;
            };
            if (region.faction) {
                wash(region.faction.colour, 0.13);
                if (region.faction.id === houseId) {
                    // The House's edge runs cyan to violet to pink across the region.
                    const pts = inset(shape.polygon, 5);
                    const xs = pts.map((p) => p[0]);
                    const ys = pts.map((p) => p[1]);
                    const [x0, x1, y0, y1] = [
                        Math.min(...xs),
                        Math.max(...xs),
                        Math.min(...ys),
                        Math.max(...ys),
                    ];
                    const stops = [
                        new THREE.Color('#41d1ff'),
                        new THREE.Color('#b39aff'),
                        new THREE.Color('#d18cff'),
                    ];
                    const colours: number[] = [];
                    for (const [x, y] of [...pts, pts[0]]) {
                        const u =
                            ((x - x0) / Math.max(1, x1 - x0) +
                                (y - y0) / Math.max(1, y1 - y0)) /
                            2;
                        const c =
                            u < 0.5
                                ? stops[0].clone().lerp(stops[1], u * 2)
                                : stops[1]
                                      .clone()
                                      .lerp(stops[2], (u - 0.5) * 2);
                        colours.push(c.r, c.g, c.b);
                    }
                    const geo = new LineGeometry();
                    geo.setPositions(outline(pts, 1));
                    geo.setColors(colours);
                    this.overlay.add(
                        new Line2(
                            geo,
                            this.lineMaterial('#ffffff', 5, {
                                vertexColors: true,
                            }),
                        ),
                    );
                } else {
                    const geo = new LineGeometry();
                    geo.setPositions(outline(inset(shape.polygon, 4), 1));
                    this.overlay.add(
                        new Line2(
                            geo,
                            this.lineMaterial(region.faction.colour, 3.5),
                        ),
                    );
                }
            } else if (region.ring === 'safe') {
                wash('#41d1ff', 0.05);
                const geo = new LineGeometry();
                geo.setPositions(outline(inset(shape.polygon, 3), 1));
                this.overlay.add(
                    new Line2(
                        geo,
                        this.lineMaterial('#41d1ff', 3, { opacity: 0.5 }),
                    ),
                );
            }
            if (region.incoming.length > 0) {
                this.pulsing.push(wash(FLAG, 0.08));
                const geo = new LineGeometry();
                geo.setPositions(outline(inset(shape.polygon, 4), 1.2));
                const mat = this.lineMaterial(FLAG, 3.5);
                this.pulsing.push(mat);
                this.overlay.add(new Line2(geo, mat));
            }
        }
        // The House flag over the Farmhouse, made once; its cloth takes the House's colour.
        const farmhouse = data.regions.find((r) => r.key === 'farmhouse');
        if (farmhouse && !this.flag) {
            const [cx, cy] = shapeFor(farmhouse).centre;
            const flag = new THREE.Group();
            const pole = new THREE.Mesh(
                new THREE.CylinderGeometry(0.9, 0.9, 46, 6),
                new THREE.MeshLambertMaterial({ color: '#8b6b4a' }),
            );
            pole.position.y = 23;
            const cloth = new THREE.Mesh(
                new THREE.PlaneGeometry(26, 15),
                new THREE.MeshLambertMaterial({ side: THREE.DoubleSide }),
            );
            cloth.position.set(13, 38, 0);
            flag.add(pole, cloth);
            flag.position.set(cx + 58, 0, cy - 40);
            this.flag = flag;
            this.scene.add(flag);
        }
        if (this.flag) {
            (
                (this.flag.children[1] as THREE.Mesh)
                    .material as THREE.MeshLambertMaterial
            ).color.set(data.house?.colour ?? '#6a6479');
        }
    }

    /** The selected region's outline: a soft glow and a solid line, over everything. */
    private buildSelection() {
        const data = this.data as WorldData;
        for (const child of this.picked.children.slice()) {
            if (child !== this.hoverMesh) {
                this.picked.remove(child);
                (child as Line2).geometry.dispose();
                const material = (child as Line2).material;
                this.lines.delete(material);
                material.dispose();
            }
        }
        const region = data.regions.find((r) => r.id === data.selectedId);
        if (!region) {
            return;
        }
        const shape = shapeFor(region);
        for (const [width, opacity] of [
            [9, 0.25],
            [2.5, 1],
        ] as const) {
            const geo = new LineGeometry();
            geo.setPositions(outline(shape.polygon, 1.5));
            const line = new Line2(
                geo,
                this.lineMaterial('#ffffff', width, { opacity, onTop: true }),
            );
            line.renderOrder = 10;
            this.picked.add(line);
        }
    }

    /** Light up the region under the cursor, or the one with keyboard focus. */
    setHover(id: number | null, focus = false) {
        this.hovered = id;
        if (this.hoverMesh) {
            this.picked.remove(this.hoverMesh);
            this.hoverMesh.geometry.dispose();
            (this.hoverMesh.material as THREE.Material).dispose();
            this.hoverMesh = null;
        }
        const region =
            id === null
                ? undefined
                : this.data?.regions.find((r) => r.id === id);
        if (!region) {
            return;
        }
        const mesh = new THREE.Mesh(
            groundShape(shapeFor(region).polygon),
            new THREE.MeshBasicMaterial({
                color: '#ffffff',
                transparent: true,
                opacity: focus ? 0.1 : 0.06,
                depthWrite: false,
            }),
        );
        mesh.position.y = 0.7;
        mesh.renderOrder = 2;
        this.hoverMesh = mesh;
        this.picked.add(mesh);
    }

    private buildLabels() {
        const data = this.data as WorldData;
        const houseId = data.house?.id ?? null;
        const seen = new Set<number>();
        for (const region of data.regions) {
            seen.add(region.id);
            let entry = this.regionLabels.get(region.id);
            if (!entry) {
                const box = div('', {
                    textAlign: 'center',
                    pointerEvents: 'none',
                    whiteSpace: 'nowrap',
                    textShadow: LABEL_SHADOW,
                    transition: 'opacity 0.2s',
                });
                const name = div('', {
                    color: LABEL,
                    fontWeight: '900',
                    letterSpacing: '0.06em',
                    fontFamily: 'system-ui, sans-serif',
                });
                const sub = div('', {
                    fontWeight: '700',
                    fontFamily: 'system-ui, sans-serif',
                    marginTop: '1px',
                });
                box.append(name, sub);
                const obj = new CSS2DObject(box);
                obj.renderOrder = 1;
                const [x, y] = labelPoint(region);
                obj.position.set(x, 6, y);
                this.scene.add(obj);
                entry = {
                    obj,
                    name,
                    sub,
                    width: shapeFor(region).box.width,
                    text: '',
                };
                this.regionLabels.set(region.id, entry);
            }
            entry.name.textContent = region.name.toUpperCase();
            const line = subtitle(
                region,
                houseId,
                data.gateNight,
                data.gateResult,
            );
            entry.sub.textContent = line?.text ?? '';
            entry.sub.style.color = line?.colour ?? LABEL;
            entry.sub.style.display = line ? '' : 'none';
            entry.text = region.name;
        }
        for (const [id, entry] of this.regionLabels) {
            if (!seen.has(id)) {
                this.scene.remove(entry.obj);
                entry.obj.element.remove();
                this.regionLabels.delete(id);
            }
        }
        this.sizeLabels(true);
    }

    private lastLabelK = 0;

    /** Labels keep about the same size on screen, growing a little as you zoom in. */
    private sizeLabels(force = false) {
        if (
            !force &&
            Math.abs(this.k - this.lastLabelK) / this.lastLabelK < 0.03
        ) {
            return;
        }
        this.lastLabelK = this.k;
        const px = labelPixels(this.k);
        for (const entry of this.regionLabels.values()) {
            // A name never runs wider than most of its region.
            const fit =
                (entry.width * 0.8 * this.k) /
                Math.max(1, entry.text.length * 0.68);
            const size = Math.max(7, Math.min(px, fit));
            entry.name.style.fontSize = `${size}px`;
            entry.sub.style.fontSize = `${Math.max(7, size * 0.82)}px`;
        }
        for (const label of this.fixedLabels) {
            label.obj.visible =
                label.minK === undefined || this.k >= label.minK;
            label.el.style.fontSize = `${Math.min(26 * this.k, px * 1.05)}px`;
        }
    }

    private buildBuildings() {
        const data = this.data as WorldData;
        this.clearGroup(this.marks);
        this.buildings = [];
        for (const region of data.regions) {
            const b = region.building;
            if (!b) {
                continue;
            }
            const colour = region.faction?.colour ?? '#8a8399';
            const group = buildingModel(b.kind, colour, b.readyAt !== null);
            const at = plot(region.key);
            const badge = div(
                '',
                {
                    background: colour,
                    color: '#16171d',
                    font: '800 10px system-ui, sans-serif',
                    minWidth: '15px',
                    height: '15px',
                    lineHeight: '15px',
                    textAlign: 'center',
                    borderRadius: '8px',
                    pointerEvents: 'none',
                    boxShadow: `0 1px 2px ${HALO}`,
                },
                `${BUILDING_GLYPH[b.kind]} ${b.level}`,
            );
            badge.style.padding = '0 4px';
            badge.title = b.label;
            const tag = new CSS2DObject(badge);
            tag.renderOrder = 2;
            tag.position.set(0, 1.25, 0);
            group.add(tag);
            this.marks.add(group);
            this.buildings.push({ group, at });
        }
        this.placeBuildings();
    }

    private placeBuildings() {
        // Like the 2D badges: the same size on screen, shrinking only when zoomed far out.
        const s = 40 / Math.max(this.k, 0.6);
        for (const { group, at } of this.buildings) {
            group.position.set(at[0], 0, at[1]);
            group.scale.setScalar(s);
        }
    }

    private buildMarches() {
        const data = this.data as WorldData;
        this.clearGroup(this.raids);
        this.marching = [];
        this.heads = [];
        const byId = new Map(data.regions.map((r) => [r.id, r]));
        const draw = (from: Point, to: Point, colour: string) => {
            const len = Math.hypot(to[0] - from[0], to[1] - from[1]);
            const nx = -(to[1] - from[1]) / len;
            const ny = (to[0] - from[0]) / len;
            const mid: Point = [
                (from[0] + to[0]) / 2 + nx * len * 0.2,
                (from[1] + to[1]) / 2 + ny * len * 0.2,
            ];
            const curve = new THREE.QuadraticBezierCurve3(
                new THREE.Vector3(from[0], 4, from[1]),
                new THREE.Vector3(mid[0], 8 + len * 0.18, mid[1]),
                new THREE.Vector3(to[0], 4, to[1]),
            );
            // Stop short of the target, like the 2D arrows.
            const stop = 1 - Math.min(0.3, 40 / len);
            const pts = curve.getPoints(48).filter((_, i) => i / 48 <= stop);
            const flatPts = pts.flatMap((p) => [p.x, p.y, p.z]);
            const glowGeo = new LineGeometry();
            glowGeo.setPositions(flatPts);
            this.raids.add(
                new Line2(
                    glowGeo,
                    this.lineMaterial(colour, 12, { opacity: 0.25 }),
                ),
            );
            const geo = new LineGeometry();
            geo.setPositions(flatPts);
            const mat = this.lineMaterial(colour, 4, { dashed: true });
            mat.dashSize = 10 / this.k;
            mat.gapSize = 8 / this.k;
            const line = new Line2(geo, mat);
            line.computeLineDistances();
            this.raids.add(line);
            this.marching.push(mat);
            const tip = pts[pts.length - 1];
            const before = pts[pts.length - 2] ?? pts[0];
            const head = new THREE.Mesh(
                new THREE.ConeGeometry(0.5, 1.2, 10),
                new THREE.MeshBasicMaterial({ color: colour }),
            );
            head.position.copy(tip);
            head.quaternion.setFromUnitVectors(
                new THREE.Vector3(0, 1, 0),
                tip.clone().sub(before).normalize(),
            );
            head.scale.setScalar(14 / this.k);
            this.raids.add(head);
            this.heads.push(head);
        };
        for (const region of data.regions) {
            for (const raid of region.incoming) {
                const from =
                    raid.from === null ? undefined : byId.get(raid.from);
                if (from) {
                    draw(
                        anchor(from.key, region.key),
                        anchor(region.key, from.key),
                        raid.faction.colour,
                    );
                }
            }
        }
        if (data.gateNight) {
            // Ironfield's men march out of the Garrison.
            draw(hub('irongate_garrison'), hub('ironfield_gate'), FLAG);
        }
        // Irrigation trenches: water running through the regions' hubs from the source to the farm.
        for (const farm of data.regions) {
            if (!farm.trench) {
                continue;
            }
            const curve = new THREE.CatmullRomCurve3(
                farm.trench.keys.map((key) => {
                    const [x, z] = hub(key);

                    return new THREE.Vector3(x, 3, z);
                }),
            );
            const flat = curve
                .getPoints(24 * farm.trench.keys.length)
                .flatMap((p) => [p.x, p.y, p.z]);
            const bank = new LineGeometry();
            bank.setPositions(flat);
            this.raids.add(new Line2(bank, this.lineMaterial('#3b2a1a', 9)));
            const water = new LineGeometry();
            water.setPositions(flat);
            this.raids.add(new Line2(water, this.lineMaterial('#5cc8ff', 4)));
        }
    }

    // ---- animals -------------------------------------------------------------------------------------------

    /** How many others show round each hub at this zoom: 3, 6 or 12, as on the 2D map. */
    private room(k = this.k) {
        return k < 0.55 ? 3 : k < 0.9 ? 6 : 12;
    }

    private lastRoom = 0;

    private syncCrowd() {
        const data = this.data as WorldData;
        const wanted = new Set(data.wanted);
        const room = this.room();
        const keep = new Set<number>();
        for (const pill of this.extras) {
            this.people.remove(pill);
        }
        this.extras = [];
        for (const region of data.regions) {
            // Outlaws first, as on the 2D map; you aren't one of the crowd.
            const others = region.animals.sample
                .filter((a) => !a.me)
                .sort(
                    (a, b) =>
                        Number(wanted.has(b.id)) - Number(wanted.has(a.id)),
                );
            const meHere = region.animals.sample.some((a) => a.me);
            const shown = others.slice(0, room);
            const centre = hub(region.key);
            shown.forEach((animal, i) => {
                keep.add(animal.id);
                const wearing = animal.wearing ?? [];
                const key = `${animal.species}|${JSON.stringify(wearing)}`;
                let entry = this.crowd.get(animal.id);
                if (entry && entry.key !== key) {
                    this.dropAnimal(entry);
                    entry = undefined;
                }
                if (!entry) {
                    const discEl = div(
                        '',
                        {
                            width: '23px',
                            height: '23px',
                            borderRadius: '50%',
                            background: '#14121a',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontSize: '13px',
                            pointerEvents: 'none',
                        },
                        speciesEmoji(animal.species),
                    );
                    const disc = new CSS2DObject(discEl);
                    disc.renderOrder = 3;
                    const ring = new THREE.Mesh(
                        this.ringShape,
                        this.ringMaterial('#8a8399'),
                    );
                    ring.rotation.x = -Math.PI / 2;
                    this.people.add(disc, ring);
                    entry = {
                        id: animal.id,
                        species: animal.species,
                        wearing,
                        key,
                        actor: null,
                        loading: false,
                        cast: false,
                        disc,
                        ring,
                        badge: null,
                        centre,
                        spot: [0, 0],
                        seed: animal.id * 7919,
                    };
                    this.crowd.set(animal.id, entry);
                }
                entry.centre = centre;
                entry.spot = SPOTS[i] ?? [0, 0];
                const isWanted = wanted.has(animal.id);
                const ringColour = isWanted
                    ? FLAG
                    : (animal.colour ?? '#8a8399');
                entry.ring.material = this.ringMaterial(ringColour);
                entry.ring.userData.thick = isWanted;
                entry.disc.element.style.boxShadow = `0 0 0 ${isWanted ? 3.2 : 2.2}px ${ringColour}`;
                if (isWanted && !entry.badge) {
                    entry.badge = new CSS2DObject(
                        div(
                            '',
                            {
                                fontSize: '13px',
                                pointerEvents: 'none',
                                filter: `drop-shadow(0 1px 2px ${HALO})`,
                            },
                            '🎯',
                        ),
                    );
                    entry.badge.renderOrder = 4;
                    this.people.add(entry.badge);
                } else if (!isWanted && entry.badge) {
                    this.people.remove(entry.badge);
                    entry.badge = null;
                }
            });
            const extra =
                region.animals.count - (meHere ? 1 : 0) - shown.length;
            if (extra > 0) {
                const pill = new CSS2DObject(
                    div(
                        '',
                        {
                            background: '#2a253a',
                            color: LABEL,
                            font: '800 11px system-ui, sans-serif',
                            padding: '2px 7px',
                            borderRadius: '10px',
                            pointerEvents: 'none',
                            boxShadow: `0 1px 2px ${HALO}`,
                        },
                        `+${extra}`,
                    ),
                );
                pill.renderOrder = 3;
                pill.userData.centre = centre;
                pill.userData.spot = SPOTS[shown.length] ?? [0, 40];
                this.people.add(pill);
                this.extras.push(pill);
            }
        }
        for (const [id, entry] of this.crowd) {
            if (!keep.has(id)) {
                this.dropAnimal(entry);
                this.crowd.delete(id);
            }
        }
        this.lastRoom = room;
        this.castModels();
        this.placeCrowd();
    }

    /** Where the camera looks, so the animals nearest it get the real models. */
    private focus: Point = [800, 500];
    private castFocus: Point | null = null;

    /** Real 3D animals for those nearest the middle of the view, up to a budget; past it, the 2D disc. */
    private castModels() {
        this.castFocus = [...this.focus];
        const [fx, fy] = this.focus;
        const near = [...this.crowd.values()].sort(
            (a, b) =>
                Math.hypot(a.centre[0] - fx, a.centre[1] - fy) -
                Math.hypot(b.centre[0] - fx, b.centre[1] - fy),
        );
        near.forEach((entry, i) => {
            entry.cast = i < this.actorBudget;
            if (entry.cast) {
                this.ensureActor(entry);
            } else if (entry.actor) {
                this.people.remove(entry.actor.root);
                entry.actor.dispose();
                entry.actor = null;
            }
        });
    }

    private ringMaterial(colour: string) {
        let material = this.ringColours.get(colour);
        if (!material) {
            material = new THREE.MeshBasicMaterial({
                color: colour,
                transparent: true,
                opacity: 0.9,
                depthWrite: false,
            });
            this.ringColours.set(colour, material);
        }
        return material;
    }

    private ensureActor(entry: CrowdEntry) {
        if (entry.actor || entry.loading) {
            return;
        }
        entry.loading = true;
        makeActor(entry.species, entry.wearing, entry.seed)
            .then((actor) => {
                entry.loading = false;
                // The view may have moved on while it downloaded.
                if (
                    this.disposed ||
                    this.crowd.get(entry.id) !== entry ||
                    !entry.cast
                ) {
                    actor.dispose();
                    return;
                }
                entry.actor = actor;
                actor.play(['Idle']);
                // Facing the viewer, give or take, so faces show.
                actor.root.rotation.y =
                    (seeded(`face:${entry.id}`) - 0.5) * 1.2;
                this.people.add(actor.root);
                this.placeCrowd();
            })
            .catch((error: unknown) => {
                // The emoji disc stays.
                entry.loading = false;
                console.warn(
                    `No 3D ${entry.species} for animal ${entry.id}`,
                    error,
                );
            });
    }

    private dropAnimal(entry: CrowdEntry) {
        if (entry.actor) {
            this.people.remove(entry.actor.root);
            entry.actor.dispose();
            entry.actor = null;
        }
        for (const o of [entry.disc, entry.badge]) {
            if (o) {
                this.people.remove(o);
            }
        }
        // Its ring's shape and colour are shared, so they stay.
        this.people.remove(entry.ring);
    }

    /**
     * How tall an animal stands, in paces, for px on screen at k 0.6: about the
     * same size on screen at any zoom, a little bigger close in.
     */
    private animalHeight(px: number) {
        return (px * (this.k / 0.6) ** 0.3) / this.k;
    }

    private placeCrowd() {
        this.changed = true;
        const h = this.animalHeight(34);
        // The 2D map's spots are screen pixels round the hub; so are these.
        const spread = 1 / this.k;
        for (const entry of this.crowd.values()) {
            const x = entry.centre[0] + entry.spot[0] * spread;
            const y = entry.centre[1] + entry.spot[1] * spread;
            entry.ring.position.set(x, 0.8, y);
            entry.ring.scale.setScalar(
                h * (entry.ring.userData.thick ? 1.25 : 1.1),
            );
            entry.disc.position.set(x, h * 0.45, y);
            entry.disc.visible = !entry.actor;
            if (entry.actor) {
                entry.actor.root.position.set(x, 0, y);
                entry.actor.root.scale.setScalar(h);
            }
            entry.badge?.position.set(x + 10 * spread, h * 1.1, y);
        }
        for (const pill of this.extras) {
            const [cx, cy] = pill.userData.centre as Point;
            const [sx, sy] = pill.userData.spot as Point;
            pill.position.set(cx + sx * spread, 2, cy + sy * spread);
        }
    }

    private homeModels: { group: THREE.Group; level: number }[] = [];

    /** Every animal's home, in its skin's colours; yours ringed in violet. */
    private buildHomes() {
        const data = this.data as WorldData;
        this.clearGroup(this.dwellings);
        this.homeModels = data.homes.map((home) => {
            const group = homeModel(home);
            group.position.set(home.x, 0, home.y);
            this.dwellings.add(group);
            return { group, level: home.level };
        });
        this.placeHomes();
    }

    private placeHomes() {
        // About the same size on screen at any zoom, big enough to spot, a Fort the biggest.
        const s = 92 / Math.max(this.k, 0.6);
        for (const { group, level } of this.homeModels) {
            group.scale.setScalar(s * (0.8 + level * 0.2));
        }
    }

    private townsfolk: {
        person: Folk;
        figure: THREE.Group;
        tag: CSS2DObject;
    }[] = [];

    /** Irongate's people as little figures, each with its name. */
    private syncFolk() {
        const data = this.data as WorldData;
        this.clearGroup(this.town);
        this.townsfolk = data.folk.map((person) => {
            const figure = makePerson(person.key);
            figure.rotation.y = (seeded(`folk:${person.key}`) - 0.5) * 1.4;
            const tag = new CSS2DObject(
                div(
                    '',
                    {
                        color: '#f5d6c4',
                        font: '800 10px system-ui, sans-serif',
                        textShadow: LABEL_SHADOW,
                        pointerEvents: 'none',
                        whiteSpace: 'nowrap',
                    },
                    person.name.split(' ').slice(-1)[0],
                ),
            );
            tag.center.set(0.5, -0.4);
            tag.renderOrder = 3;
            this.town.add(figure, tag);
            return { person, figure, tag };
        });
        this.placeFolk();
    }

    private placeFolk() {
        const h = this.animalHeight(34);
        for (const { person, figure, tag } of this.townsfolk) {
            const [hx, hy] = hub(person.district);
            const x = hx + person.spot[0];
            const y = hy + person.spot[1];
            figure.position.set(x, 0, y);
            figure.scale.setScalar(h);
            tag.position.set(x, 0, y);
            tag.visible = this.k >= 0.7;
        }
    }

    /** One of Irongate's people under a point on screen, if any, for their card. */
    folkAt(
        screen: Point,
        project: (point: Point, height: number) => Point,
    ): Folk | null {
        const h = this.animalHeight(34);
        const reach = Math.max(18, h * this.k * 0.55);
        let found: Folk | null = null;
        let nearest = reach;
        for (const { person } of this.townsfolk) {
            const [hx, hy] = hub(person.district);
            const [sx, sy] = project(
                [hx + person.spot[0], hy + person.spot[1]],
                h * 0.5,
            );
            const d = Math.hypot(sx - screen[0], sy - screen[1]);
            if (d < nearest) {
                nearest = d;
                found = person;
            }
        }
        return found;
    }

    private syncMe() {
        const data = this.data as WorldData;
        const me = this.me;
        const show = data.me !== null && data.species !== null;
        me.ring.visible = me.ping.visible = me.tag.visible = show;
        if (!show) {
            me.disc.visible = false;
            if (me.actor) {
                this.scene.remove(me.actor.root);
                me.actor.dispose();
                me.actor = null;
                me.key = '';
            }
            return;
        }
        (me.disc.element as HTMLDivElement).textContent = speciesEmoji(
            data.species as Species,
        );
        const key = `${data.species}|${JSON.stringify(data.wearing)}`;
        if (key !== me.key) {
            me.key = key;
            const old = me.actor;
            makeActor(data.species as Species, data.wearing, 1)
                .then((actor) => {
                    if (this.disposed || me.key !== key) {
                        actor.dispose();
                        return;
                    }
                    if (old) {
                        this.scene.remove(old.root);
                        old.dispose();
                    }
                    me.actor = actor;
                    this.scene.add(actor.root);
                    this.changed = true;
                })
                .catch((error: unknown) => {
                    // Your emoji disc stays.
                    console.warn(`No 3D ${data.species} for you`, error);
                });
        }
    }

    /** The other animal under a point on screen, if any, for its menu. */
    animalAt(
        screen: Point,
        project: (point: Point, height: number) => Point,
    ): MapAnimal | null {
        const h = this.animalHeight(34);
        const spread = 1 / this.k;
        // About half its height on screen, and never a fiddly target.
        const reach = Math.max(18, h * this.k * 0.55);
        let found: CrowdEntry | null = null;
        let nearest = reach;
        for (const entry of this.crowd.values()) {
            const at: Point = [
                entry.centre[0] + entry.spot[0] * spread,
                entry.centre[1] + entry.spot[1] * spread,
            ];
            const [sx, sy] = project(at, h * 0.45);
            const d = Math.hypot(sx - screen[0], sy - screen[1]);
            if (d < nearest) {
                nearest = d;
                found = entry;
            }
        }
        if (!found) {
            return null;
        }
        const id = found.id;
        return (
            this.data?.regions
                .flatMap((r) => r.animals.sample)
                .find((a) => a.id === id) ?? null
        );
    }

    /** Region names step back where they would cover your animal, which the 2D map draws over them. */
    fadeLabels(project: (point: Point, height: number) => Point) {
        const at = this.me.shown;
        const shown = at !== null && this.me.ring.visible;
        const [ax, ay] = shown
            ? project(at, this.animalHeight(40) * 0.5)
            : [0, 0];
        for (const entry of this.regionLabels.values()) {
            let near = false;
            if (shown) {
                const [lx, ly] = project(
                    [entry.obj.position.x, entry.obj.position.z],
                    entry.obj.position.y,
                );
                near = Math.abs(lx - ax) < 64 && Math.abs(ly - ay) < 36;
            }
            entry.obj.element.style.opacity = near ? '0.3' : '';
        }
    }

    /** Where your animal is, or null when it isn't on the map. */
    herePoint(now = Date.now()): Point | null {
        const data = this.data;
        if (!data?.me) {
            return null;
        }
        return walkPoint(data.me, now);
    }

    // ---- every frame ---------------------------------------------------------------------------------------

    /** Something changed that should be drawn at once, not at the calm idle rate. */
    get urgent() {
        // Your animal walking is what you watch, so it moves at full speed.
        return this.changed || this.me.walking;
    }

    /**
     * Animate one frame, dt ms after the last. Returns whether labels moved
     * too (zoom, new data, your animal walking), not only the 3D scene.
     */
    tick(
        now: number,
        dt: number,
        k: number,
        cameraDistance: number,
        focus: Point,
    ): boolean {
        const data = this.data;
        if (!data) {
            return false;
        }
        const zoomed = Math.abs(k - this.k) > 1e-6;
        this.k = k;
        this.focus = focus;
        if (
            this.castFocus &&
            Math.hypot(
                focus[0] - this.castFocus[0],
                focus[1] - this.castFocus[1],
            ) > 250
        ) {
            this.castModels();
            this.placeCrowd();
        }
        const fog = this.scene.fog as THREE.Fog;
        fog.near = cameraDistance * 1.1;
        fog.far = cameraDistance * 3.2;
        if (zoomed) {
            this.sizeLabels();
            this.placeBuildings();
            if (this.room(k) !== this.lastRoom) {
                this.syncCrowd();
            } else {
                this.placeCrowd();
            }
            this.placeFolk();
            this.placeHomes();
            for (const m of this.marching) {
                m.dashSize = 10 / k;
                m.gapSize = 8 / k;
            }
            for (const head of this.heads) {
                head.scale.setScalar(14 / k);
            }
        }
        const reduce = reducedMotion();
        if (!reduce) {
            // Raid pulse: 2 s, between full and half strength, as Tailwind's animate-pulse.
            const pulse = 0.75 + 0.25 * Math.cos((now / 2000) * Math.PI * 2);
            for (const m of this.pulsing) {
                m.opacity = m instanceof LineMaterial ? pulse : 0.08 * pulse;
            }
            // The march dashes move about 15 px a second, as on the 2D map.
            for (const m of this.marching) {
                m.dashOffset -= (dt * 0.015) / k;
            }
            this.flag?.children[1]?.rotation.set(
                0,
                Math.sin(now / 700) * 0.25,
                0,
            );
            this.marks3d?.animate(now);
        }

        let moving = zoomed || this.changed;
        this.changed = false;
        const me = this.me;
        const target = this.herePoint(now);
        if (target) {
            const walk = data.me?.walk ?? null;
            const walking = walk !== null && now < Date.parse(walk.arrivesAt);
            // Along a walk it follows the clock exactly; after a reload moves it, it eases over.
            if (
                !me.shown ||
                Math.hypot(target[0] - me.shown[0], target[1] - me.shown[1]) >
                    600
            ) {
                me.shown = [...target];
                moving = true;
            } else if (target[0] !== me.shown[0] || target[1] !== me.shown[1]) {
                const t = walking ? 1 : Math.min(1, dt / 350);
                me.shown = [
                    me.shown[0] + (target[0] - me.shown[0]) * t,
                    me.shown[1] + (target[1] - me.shown[1]) * t,
                ];
                if (
                    Math.hypot(
                        target[0] - me.shown[0],
                        target[1] - me.shown[1],
                    ) < 0.05
                ) {
                    me.shown = [...target];
                }
                moving = true;
            }
            const [x, y] = me.shown;
            const h = this.animalHeight(40);
            me.ring.position.set(x, 0.9, y);
            me.ring.scale.setScalar(h * 1.2);
            me.ping.position.set(x, 0.85, y);
            if (reduce) {
                me.ping.visible = false;
            } else {
                const phase = (now % 2200) / 2200;
                me.ping.scale.setScalar(h * 1.2 * (0.8 + phase * 1.1));
                (me.ping.material as THREE.MeshBasicMaterial).opacity =
                    0.6 * (1 - phase);
            }
            me.tag.position.set(x, 0, y);
            me.disc.position.set(x, h * 0.5, y);
            me.disc.visible = !me.actor;
            if (me.actor) {
                me.actor.root.position.set(x, 0, y);
                me.actor.root.scale.setScalar(h);
                if (walk) {
                    // Face the way it walks (the model faces +z, which is south).
                    me.actor.root.rotation.y = Math.atan2(
                        walk.to[0] - walk.from[0],
                        walk.to[1] - walk.from[1],
                    );
                }
                if (walking !== me.walking || me.actor !== me.playing) {
                    // A Speedo doubles the pace: quadrupeds gallop, birds run.
                    const fast = (data.me?.pace ?? 0) > 750;
                    me.actor.play(
                        walking
                            ? fast
                                ? ['Gallop', 'Run', 'Walk']
                                : ['Walk']
                            : ['Idle'],
                    );
                    me.playing = me.actor;
                }
            }
            me.walking = walking;
            if (walking && walk) {
                const [tx, ty] = walk.to;
                setSegment(me.line, [x, 1.3, y], [tx, 1.3, ty]);
                me.lineMat.dashSize = 8 / k;
                me.lineMat.gapSize = 6 / k;
                me.line.visible = true;
                me.end.visible = true;
                me.end.position.set(tx, 1, ty);
                me.end.scale.setScalar(9 / k);
            } else {
                me.line.visible = false;
                me.end.visible = false;
            }
        }

        // Every animal breathes; under reduced motion only yours moves.
        if (!reduce) {
            for (const entry of this.crowd.values()) {
                entry.actor?.mixer.update(dt / 1000);
            }
        }
        me.actor?.mixer.update(dt / 1000);
        return moving;
    }

    dispose() {
        this.disposed = true;
        for (const entry of this.crowd.values()) {
            this.dropAnimal(entry);
        }
        this.crowd.clear();
        this.me.actor?.dispose();
        this.ringShape.dispose();
        this.ringColours.forEach((material) => material.dispose());
        this.scene.traverse((o) => {
            if (o instanceof THREE.InstancedMesh) {
                o.dispose();
            }
            const mesh = o as THREE.Mesh;
            mesh.geometry?.dispose();
            const mats = Array.isArray(mesh.material)
                ? mesh.material
                : mesh.material
                  ? [mesh.material]
                  : [];
            mats.forEach((m) => {
                const withMap = m as THREE.MeshLambertMaterial;
                withMap.map?.dispose();
                m.dispose();
            });
            if (o instanceof CSS2DObject) {
                o.element.remove();
            }
        });
    }
}

/** An animal's home, about 1 unit across: walls and a roof in its skin's colours, battlements on a Fort. */
function homeModel(home: MapHome): THREE.Group {
    const g = new THREE.Group();
    const going = home.readyAt !== null;
    const look = (color: string) =>
        new THREE.MeshLambertMaterial({
            color,
            flatShading: true,
            transparent: going,
            opacity: going ? 0.55 : 1,
        });
    const wall = look(home.skin?.colours[1] ?? '#cfc3ad');
    const roof = look(home.skin?.colours[0] ?? '#6a6479');
    if (home.level === 0) {
        const tent = new THREE.Mesh(
            new THREE.ConeGeometry(0.42, 0.6, 3),
            look(home.skin?.colours[0] ?? '#b9a57c'),
        );
        tent.position.y = 0.3;
        g.add(tent);
        if (home.mine) {
            const ring = new THREE.Mesh(
                new THREE.RingGeometry(0.5, 0.6, 24),
                new THREE.MeshBasicMaterial({
                    color: VIOLET,
                    depthWrite: false,
                }),
            );
            ring.rotation.x = -Math.PI / 2;
            ring.position.y = 0.02;
            g.add(ring);
        }
        return g;
    }
    const walls = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.45, 0.55), wall);
    walls.position.y = 0.225;
    g.add(walls);
    if (home.level === 3) {
        for (const [x, z] of [
            [-0.3, -0.22],
            [0.3, -0.22],
            [-0.3, 0.22],
            [0.3, 0.22],
            [0, -0.22],
            [0, 0.22],
        ]) {
            const merlon = new THREE.Mesh(
                new THREE.BoxGeometry(0.12, 0.12, 0.12),
                roof,
            );
            merlon.position.set(x, 0.51, z);
            g.add(merlon);
        }
    } else {
        const top = new THREE.Mesh(new THREE.ConeGeometry(0.52, 0.35, 4), roof);
        top.position.y = 0.62;
        top.rotation.y = Math.PI / 4;
        g.add(top);
    }
    if (home.mine) {
        const ring = new THREE.Mesh(
            new THREE.RingGeometry(0.55, 0.65, 24),
            new THREE.MeshBasicMaterial({ color: VIOLET, depthWrite: false }),
        );
        ring.rotation.x = -Math.PI / 2;
        ring.position.y = 0.02;
        g.add(ring);
    }
    return g;
}

/** A little building for each kind, about 1 unit across, in the holder's colour. */
function buildingModel(
    kind: BuildingKindValue,
    colour: string,
    going: boolean,
): THREE.Group {
    const g = new THREE.Group();
    const opacity = going ? 0.55 : 1;
    const wall = new THREE.MeshLambertMaterial({
        color: '#d9cfbd',
        transparent: going,
        opacity,
        flatShading: true,
    });
    const roof = new THREE.MeshLambertMaterial({
        color: colour,
        transparent: going,
        opacity,
        flatShading: true,
    });
    const wood = new THREE.MeshLambertMaterial({
        color: '#8b6b4a',
        transparent: going,
        opacity,
        flatShading: true,
    });
    const add = (
        geo: THREE.BufferGeometry,
        mat: THREE.Material,
        x: number,
        y: number,
        z: number,
        ry = 0,
    ) => {
        const m = new THREE.Mesh(geo, mat);
        m.position.set(x, y, z);
        m.rotation.y = ry;
        g.add(m);
        return m;
    };
    if (kind === 'den') {
        add(new THREE.BoxGeometry(0.7, 0.4, 0.6), wall, 0, 0.2, 0);
        add(new THREE.ConeGeometry(0.55, 0.4, 4), roof, 0, 0.6, 0, Math.PI / 4);
    } else if (kind === 'watchtower') {
        for (const [x, z] of [
            [-0.18, -0.18],
            [0.18, -0.18],
            [-0.18, 0.18],
            [0.18, 0.18],
        ]) {
            add(new THREE.CylinderGeometry(0.04, 0.05, 1, 5), wood, x, 0.5, z);
        }
        add(new THREE.BoxGeometry(0.55, 0.12, 0.55), wood, 0, 1, 0);
        add(
            new THREE.ConeGeometry(0.42, 0.35, 4),
            roof,
            0,
            1.23,
            0,
            Math.PI / 4,
        );
    } else if (kind === 'drill_yard') {
        add(
            new THREE.CylinderGeometry(0.55, 0.55, 0.04, 16),
            new THREE.MeshLambertMaterial({
                color: '#6b5a44',
                transparent: going,
                opacity,
            }),
            0,
            0.02,
            0,
        );
        for (let i = 0; i < 10; i++) {
            const a = (i / 10) * Math.PI * 2;
            add(
                new THREE.CylinderGeometry(0.025, 0.025, 0.25, 4),
                wood,
                Math.cos(a) * 0.55,
                0.13,
                Math.sin(a) * 0.55,
            );
        }
        add(new THREE.BoxGeometry(0.05, 0.7, 0.05), wood, 0, 0.35, 0);
        add(new THREE.BoxGeometry(0.3, 0.2, 0.02), roof, 0.15, 0.6, 0);
    } else if (kind === 'storehouse') {
        add(new THREE.BoxGeometry(0.9, 0.45, 0.6), wall, 0, 0.225, 0);
        const r = add(
            new THREE.CylinderGeometry(0.34, 0.34, 0.9, 3, 1),
            roof,
            0,
            0.55,
            0,
        );
        r.rotation.z = Math.PI / 2;
        r.scale.set(1, 1, 0.9);
    } else if (kind === 'workshop') {
        add(new THREE.BoxGeometry(0.75, 0.42, 0.6), wall, 0, 0.21, 0);
        add(
            new THREE.ConeGeometry(0.55, 0.32, 4),
            roof,
            0,
            0.58,
            0,
            Math.PI / 4,
        );
        add(
            new THREE.BoxGeometry(0.1, 0.35, 0.1),
            new THREE.MeshLambertMaterial({
                color: '#6a6479',
                transparent: going,
                opacity,
            }),
            0.22,
            0.65,
            0.12,
        );
    } else {
        // The Head Office: columns, a pediment and a flag.
        add(new THREE.BoxGeometry(1, 0.1, 0.7), wall, 0, 0.05, 0);
        for (let i = 0; i < 4; i++) {
            add(
                new THREE.CylinderGeometry(0.05, 0.05, 0.5, 8),
                wall,
                -0.36 + i * 0.24,
                0.35,
                0.3,
            );
        }
        add(new THREE.BoxGeometry(0.9, 0.5, 0.45), wall, 0, 0.35, -0.08);
        const p = add(
            new THREE.CylinderGeometry(0.3, 0.3, 1.02, 3, 1),
            roof,
            0,
            0.7,
            0,
        );
        p.rotation.z = Math.PI / 2;
        p.scale.set(1, 1, 0.55);
        add(new THREE.CylinderGeometry(0.015, 0.015, 0.5, 4), wood, 0, 1.05, 0);
        add(new THREE.BoxGeometry(0.2, 0.12, 0.01), roof, 0.1, 1.22, 0);
    }
    if (going) {
        // Scaffolding round a building that's going up.
        const scaffold = new THREE.LineSegments(
            new THREE.EdgesGeometry(new THREE.BoxGeometry(1.05, 0.9, 0.8)),
            new THREE.LineBasicMaterial({ color: '#c8a46a' }),
        );
        scaffold.position.y = 0.45;
        g.add(scaffold);
    }
    return g;
}
