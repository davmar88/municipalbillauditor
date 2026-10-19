import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import type { MapHome } from '@/types/game';

/**
 * Homes built from the Medieval Village MegaKit by Quaternius (CC0): walls
 * two paces wide and three high, roofs to fit 4, 6 and 8 paces square. Each
 * level is put together once from the pieces, then copied for every home of
 * that level and coloured in its owner's skin.
 */

const PIECES = [
    'Wall_Plaster_WoodGrid',
    'Wall_Plaster_Door_Round',
    'Wall_Plaster_Window_Wide_Round',
    'Wall_Plaster_Straight',
    'Wall_UnevenBrick_Straight',
    'Wall_UnevenBrick_Door_Round',
    'Wall_UnevenBrick_Window_Wide_Round',
    'Roof_RoundTiles_4x4',
    'Roof_RoundTiles_6x6',
    'Roof_RoundTiles_8x8',
    'Roof_Tower_RoundTiles',
    'Prop_Chimney',
    'Corner_Exterior_Wood',
    'Corner_Exterior_Brick',
] as const;

type Piece = (typeof PIECES)[number];

const WALL_HEIGHT = 3.12;

/** Each material's own colour, for homes without a skin. */
const COLOURS: Record<string, string> = {
    MI_Plaster: '#e3d7c1',
    MI_UnevenBrick: '#9a8e7f',
    MI_WoodTrim: '#6b4a30',
    MI_WoodTrim_Wear: '#6b4a30',
    MI_RoundTiles: '#a94b3a',
    MI_RockTrim: '#857f78',
    MI_Brick: '#985a45',
    MI_MetalOrnaments: '#3d3d44',
};

/** What a skin recolours: its second colour the walls, its first the roof. */
const WALLS = new Set(['MI_Plaster', 'MI_UnevenBrick']);
const ROOFS = new Set(['MI_RoundTiles']);

let pieces: Promise<Map<Piece, THREE.Object3D>> | null = null;
let ready: Map<Piece, THREE.Object3D> | null = null;

/** Download the pieces once; resolves when homes can be built from them. */
export function loadVillage(): Promise<void> {
    if (!pieces) {
        const loader = new GLTFLoader();
        pieces = Promise.all(
            PIECES.map((name) =>
                loader
                    .loadAsync(`/models/village/${name}.gltf`)
                    .then((gltf) => [name, gltf.scene] as const),
            ),
        ).then((list) => {
            ready = new Map(list);
            return ready;
        });
        // A failed download can be tried again later.
        pieces.catch(() => {
            pieces = null;
        });
    }
    return pieces.then(() => undefined);
}

function piece(name: Piece): THREE.Object3D {
    const found = ready?.get(name);
    if (!found) {
        throw new Error(`The ${name} piece hasn't loaded.`);
    }
    const copy = found.clone();
    copy.traverse((o) => {
        const mesh = o as THREE.Mesh;
        if (mesh.isMesh) {
            const list = Array.isArray(mesh.material)
                ? mesh.material
                : [mesh.material];
            mesh.userData.materials = list.map((m) => m.name);
        }
    });
    return copy;
}

/**
 * Walls round a square N walls a side, centred on the origin, their fronts
 * facing out; `front` names the pieces along the front, the door among them.
 */
function walls(
    g: THREE.Group,
    n: number,
    plain: Piece,
    front: Piece[],
    sides: (i: number) => Piece,
    lift = 0,
) {
    const half = n;
    for (let i = 0; i < n; i++) {
        const along = -half + 1 + 2 * i;
        const sides4: [Piece, number, number, number][] = [
            [front[i] ?? plain, along, half, 0],
            [sides(i), along, -half, Math.PI],
            [sides(i), half, -along, Math.PI / 2],
            [sides(i), -half, along, -Math.PI / 2],
        ];
        for (const [name, x, z, turn] of sides4) {
            const wall = piece(name);
            wall.position.set(x, lift, z);
            wall.rotation.y = turn;
            g.add(wall);
        }
    }
}

function corners(g: THREE.Group, n: number, post: Piece, storeys = 1) {
    for (const [x, z] of [
        [n, n],
        [-n, n],
        [n, -n],
        [-n, -n],
    ]) {
        for (let s = 0; s < storeys; s++) {
            const p = piece(post);
            p.position.set(x, s * WALL_HEIGHT, z);
            g.add(p);
        }
    }
}

function roof(g: THREE.Group, name: Piece, y: number, x = 0, z = 0) {
    const r = piece(name);
    r.position.set(x, y, z);
    g.add(r);
}

/** A tower two walls a side, so many storeys high, with a pointed roof. */
function tower(g: THREE.Group, x: number, z: number, storeys: number) {
    const t = new THREE.Group();
    for (let s = 0; s < storeys; s++) {
        walls(
            t,
            2,
            'Wall_UnevenBrick_Straight',
            [],
            (i) =>
                i === 0 && s > 0
                    ? 'Wall_UnevenBrick_Window_Wide_Round'
                    : 'Wall_UnevenBrick_Straight',
            s * WALL_HEIGHT,
        );
    }
    corners(t, 2, 'Corner_Exterior_Brick', storeys);
    roof(t, 'Roof_Tower_RoundTiles', storeys * WALL_HEIGHT);
    t.position.set(x, 0, z);
    g.add(t);
}

/** One level's home, put together from the pieces, in paces. */
function assemble(level: number): THREE.Group {
    const g = new THREE.Group();
    if (level <= 1) {
        // A Hut: four paces square, timbered, one door.
        walls(
            g,
            2,
            'Wall_Plaster_WoodGrid',
            ['Wall_Plaster_Door_Round', 'Wall_Plaster_WoodGrid'],
            () => 'Wall_Plaster_WoodGrid',
        );
        corners(g, 2, 'Corner_Exterior_Wood');
        roof(g, 'Roof_RoundTiles_4x4', WALL_HEIGHT);
    } else if (level === 2) {
        // A Cottage: six paces, windows all round and a chimney.
        walls(
            g,
            3,
            'Wall_Plaster_Straight',
            [
                'Wall_Plaster_Window_Wide_Round',
                'Wall_Plaster_Door_Round',
                'Wall_Plaster_Window_Wide_Round',
            ],
            (i) =>
                i === 1
                    ? 'Wall_Plaster_Window_Wide_Round'
                    : 'Wall_Plaster_WoodGrid',
        );
        corners(g, 3, 'Corner_Exterior_Wood');
        roof(g, 'Roof_RoundTiles_6x6', WALL_HEIGHT);
        const chimney = piece('Prop_Chimney');
        chimney.position.set(1.8, WALL_HEIGHT + 1.2, -1.8);
        g.add(chimney);
    } else {
        // A Fort and up: eight paces of stone, with a tower for a Keep and taller ones for Castles.
        walls(
            g,
            4,
            'Wall_UnevenBrick_Straight',
            [
                'Wall_UnevenBrick_Window_Wide_Round',
                'Wall_UnevenBrick_Door_Round',
                'Wall_UnevenBrick_Straight',
                'Wall_UnevenBrick_Window_Wide_Round',
            ],
            (i) =>
                i % 2 === 1
                    ? 'Wall_UnevenBrick_Window_Wide_Round'
                    : 'Wall_UnevenBrick_Straight',
        );
        corners(g, 4, 'Corner_Exterior_Brick');
        roof(g, 'Roof_RoundTiles_8x8', WALL_HEIGHT);
        if (level >= 4) {
            tower(g, 4, -4, Math.min(4, level - 2));
        }
        if (level >= 6) {
            tower(g, -4, -4, Math.min(4, level - 3));
        }
    }
    return g;
}

const templates = new Map<number, THREE.Group>();

/** How many paces of kit make one unit on the map: a Hut comes out about two thirds across, a Fort about one. */
const PACES = 9;

const materials = new Map<string, THREE.MeshLambertMaterial>();

function material(colour: string, going: boolean): THREE.MeshLambertMaterial {
    const key = `${colour}:${going}`;
    let found = materials.get(key);
    if (!found) {
        found = new THREE.MeshLambertMaterial({
            color: colour,
            transparent: going,
            opacity: going ? 0.55 : 1,
        });
        materials.set(key, found);
    }
    return found;
}

/**
 * A home built from the kit, standing on the ground at its origin, a Hut
 * about one unit across, or null until the pieces have loaded or for a Tent.
 */
export function villageHome(home: MapHome): THREE.Group | null {
    if (!ready || home.level < 1) {
        return null;
    }
    const level = Math.min(home.level, 7);
    let template = templates.get(level);
    if (!template) {
        template = assemble(level);
        template.scale.setScalar(1 / PACES);
        templates.set(level, template);
    }
    // The map scales the home; the kit's own scale stays on the model inside.
    const model = template.clone();
    const g = new THREE.Group();
    g.add(model);
    const going = home.readyAt !== null;
    const wall = home.skin?.colours[1];
    const top = home.skin?.colours[0];
    model.traverse((o) => {
        const mesh = o as THREE.Mesh;
        if (!mesh.isMesh) {
            return;
        }
        mesh.userData.shared = true;
        const names = (mesh.userData.materials ?? []) as string[];
        const colours = names.map((name) =>
            WALLS.has(name) && wall
                ? wall
                : ROOFS.has(name) && top
                  ? top
                  : (COLOURS[name] ?? '#8a8399'),
        );
        mesh.material =
            colours.length > 1
                ? colours.map((c) => material(c, going))
                : material(colours[0] ?? '#8a8399', going);
    });
    return g;
}
