import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import type { Point } from '@/lib/hollowmere';
import { seeded } from '@/lib/hollowmere';

/**
 * Trees, bushes, rocks and flowers from Quaternius's Ultimate Nature Pack
 * (CC0), scattered at random over the valley in place of the simple cones
 * and pebbles. Each model is drawn many times as one instanced mesh per part.
 */

const TREES = [
    'CommonTree_1',
    'CommonTree_2',
    'CommonTree_3',
    'CommonTree_4',
    'CommonTree_5',
    'PineTree_1',
    'PineTree_2',
    'PineTree_3',
    'PineTree_4',
    'PineTree_5',
    'BirchTree_1',
    'BirchTree_2',
    'BirchTree_3',
    'BirchTree_4',
    'BirchTree_5',
    'Willow_1',
    'Willow_2',
    'Willow_3',
    'CommonTree_Autumn_1',
    'CommonTree_Autumn_2',
    'BirchTree_Autumn_1',
] as const;

const BUSHES = [
    'Bush_1',
    'Bush_2',
    'BushBerries_1',
    'BushBerries_2',
    'Plant_1',
    'Plant_3',
] as const;

const SCRUB = [
    ...BUSHES,
    'TreeStump',
    'TreeStump_Moss',
    'WoodLog',
    'WoodLog_Moss',
    'Rock_Moss_1',
    'Rock_Moss_2',
    'Plant_2',
] as const;

const ROCKS = [
    'Rock_1',
    'Rock_2',
    'Rock_3',
    'Rock_4',
    'Rock_5',
    'Rock_Moss_1',
    'Rock_Moss_2',
    'Rock_Moss_3',
] as const;

const FLOWERS = ['Flowers', 'Grass', 'Grass_2', 'Plant_2'] as const;

type Model =
    | (typeof TREES)[number]
    | (typeof SCRUB)[number]
    | (typeof ROCKS)[number]
    | (typeof FLOWERS)[number];

/** How tall each kind stands on the map, in paces. */
const HEIGHT = { tree: 44, bush: 15, scrub: 14, rock: 11, flower: 7 };

type Part = { geometry: THREE.BufferGeometry; material: THREE.Material };

const parts = new Map<Model, Part[]>();
let loading: Promise<void> | null = null;

/** Every model's parts, scaled to one unit tall and standing at the origin. */
export function loadNature(): Promise<void> {
    if (!loading) {
        const loader = new GLTFLoader();
        const all = [...new Set([...TREES, ...SCRUB, ...ROCKS, ...FLOWERS])];
        loading = Promise.all(
            all.map((name) =>
                loader.loadAsync(`/models/nature/${name}.glb`).then((gltf) => {
                    gltf.scene.updateMatrixWorld(true);
                    const box = new THREE.Box3().setFromObject(gltf.scene);
                    const tall = Math.max(0.01, box.max.y - box.min.y);
                    const unit = new THREE.Matrix4().makeScale(
                        1 / tall,
                        1 / tall,
                        1 / tall,
                    );
                    const list: Part[] = [];
                    gltf.scene.traverse((o) => {
                        const mesh = o as THREE.Mesh;
                        if (!mesh.isMesh) {
                            return;
                        }
                        const mats = Array.isArray(mesh.material)
                            ? mesh.material
                            : [mesh.material];
                        const base = mesh.geometry.clone();
                        base.applyMatrix4(
                            new THREE.Matrix4().multiplyMatrices(
                                unit,
                                mesh.matrixWorld,
                            ),
                        );
                        // One part per material: the trunk, the leaves, the flowers.
                        const groups = base.groups.length
                            ? base.groups
                            : [{ start: 0, count: Infinity, materialIndex: 0 }];
                        for (const group of groups) {
                            const geometry = base.clone();
                            geometry.clearGroups();
                            if (Number.isFinite(group.count)) {
                                geometry.setDrawRange(group.start, group.count);
                            }
                            const source = mats[group.materialIndex ?? 0] as
                                | THREE.MeshStandardMaterial
                                | undefined;
                            list.push({
                                geometry,
                                material: new THREE.MeshLambertMaterial({
                                    color: source?.color ?? '#557a4a',
                                    flatShading: true,
                                }),
                            });
                        }
                    });
                    parts.set(name, list);
                }),
            ),
        ).then(() => undefined);
        loading.catch(() => {
            loading = null;
        });
    }
    return loading;
}

export type NatureSpots = {
    /** Tree spots, with how big a tree the 2D map draws there (1 a woodland tree, 0.6 a meadow bush). */
    trees: [number, number, number][];
    /** The open scrub between the regions. */
    scrub: Point[];
    /** Stony ground. */
    rocks: Point[];
    /** Meadows and grassland. */
    flowers: Point[];
};

/** Pick one of a list by a point, the same every time. */
function pick<T>(list: readonly T[], [x, y]: Point, salt: string): T {
    return list[
        Math.floor(
            seeded(`${salt}:${x.toFixed(1)},${y.toFixed(1)}`) * list.length,
        ) % list.length
    ];
}

/** The whole valley's nature, as instanced meshes in one group. */
export function buildNature(spots: NatureSpots): THREE.Group {
    const placed = new Map<Model, { at: Point; height: number }[]>();
    const add = (model: Model, at: Point, height: number) => {
        const list = placed.get(model) ?? [];
        list.push({ at, height });
        placed.set(model, list);
    };

    for (const [x, y, s] of spots.trees) {
        const at: Point = [x, y];
        if (s >= 0.8) {
            add(pick(TREES, at, 'tree'), at, HEIGHT.tree * s);
        } else {
            add(pick(BUSHES, at, 'bush'), at, HEIGHT.bush);
        }
    }
    for (const at of spots.scrub) {
        // Most of the scrub is bushes; now and then a lone tree, a stump or a log.
        const roll = seeded(`scrub:${at[0].toFixed(1)},${at[1].toFixed(1)}`);
        if (roll < 0.08) {
            add(pick(TREES, at, 'scrubtree'), at, HEIGHT.tree * 0.8);
        } else {
            add(pick(SCRUB, at, 'scrub'), at, HEIGHT.scrub);
        }
    }
    for (const at of spots.rocks) {
        add(pick(ROCKS, at, 'rock'), at, HEIGHT.rock);
    }
    for (const at of spots.flowers) {
        add(pick(FLOWERS, at, 'flower'), at, HEIGHT.flower);
    }

    const group = new THREE.Group();
    const m = new THREE.Matrix4();
    const q = new THREE.Quaternion();
    const up = new THREE.Vector3(0, 1, 0);
    const scale = new THREE.Vector3();
    const p = new THREE.Vector3();
    for (const [model, list] of placed) {
        for (const part of parts.get(model) ?? []) {
            const mesh = new THREE.InstancedMesh(
                part.geometry,
                part.material,
                list.length,
            );
            list.forEach(({ at: [x, y], height }, i) => {
                const r = seeded(
                    `nature:${model}:${x.toFixed(1)},${y.toFixed(1)}`,
                );
                const k = height * (0.8 + r * 0.45);
                q.setFromAxisAngle(up, r * Math.PI * 2);
                scale.set(k, k, k);
                p.set(x, 0, y);
                mesh.setMatrixAt(i, m.compose(p, q, scale));
            });
            mesh.userData.shared = true;
            group.add(mesh);
        }
    }
    return group;
}
