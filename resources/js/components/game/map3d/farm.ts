import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import type { BuildingKindValue } from '@/types/game';

/**
 * Farm buildings from Quaternius's LowPoly Farm Buildings (CC0): barns,
 * silos, windmills, a well, a coop and a water tower. They stand for the
 * factions' buildings and dress the farms and Hollowmere's yards.
 */

export const FARM_MODELS = [
    'Barn',
    'BigBarn',
    'ChickenCoop',
    'OpenBarn',
    'Silo',
    'Silo_House',
    'SmallBarn',
    'TowerWindmill',
    'WaterTower',
    'Well',
    'Windmill',
] as const;

export type FarmModel = (typeof FARM_MODELS)[number];

/** Which building stands for each kind a faction can raise. */
export const FOR_KIND: Record<BuildingKindValue, FarmModel> = {
    den: 'SmallBarn',
    watchtower: 'WaterTower',
    drill_yard: 'OpenBarn',
    storehouse: 'Silo_House',
    workshop: 'Barn',
    head_office: 'BigBarn',
};

let loading: Promise<void> | null = null;
const ready = new Map<FarmModel, THREE.Object3D>();

/** Download the buildings once; resolves when they can be placed. */
export function loadFarm(): Promise<void> {
    if (!loading) {
        const loader = new GLTFLoader();
        loading = Promise.all(
            FARM_MODELS.map((name) =>
                loader.loadAsync(`/models/farm/${name}.glb`).then((gltf) => {
                    // About one unit across, the tall ones kept from towering too far.
                    const box = new THREE.Box3().setFromObject(gltf.scene);
                    const size = box.getSize(new THREE.Vector3());
                    const unit = Math.max(size.x, size.z, size.y / 1.6);
                    const model = new THREE.Group();
                    model.add(gltf.scene);
                    model.scale.setScalar(1 / unit);
                    gltf.scene.traverse((o) => {
                        const mesh = o as THREE.Mesh;
                        if (mesh.isMesh) {
                            const list = Array.isArray(mesh.material)
                                ? mesh.material
                                : [mesh.material];
                            mesh.userData.materials = list.map((m) => ({
                                name: m.name,
                                colour: `#${(m as THREE.MeshStandardMaterial).color.getHexString()}`,
                            }));
                        }
                    });
                    ready.set(name, model);
                }),
            ),
        ).then(() => undefined);
        // A failed download can be tried again later.
        loading.catch(() => {
            loading = null;
            ready.clear();
        });
    }
    return loading;
}

export function farmReady(): boolean {
    return ready.size === FARM_MODELS.length;
}

const materials = new Map<string, THREE.MeshLambertMaterial>();

function material(colour: string, going: boolean): THREE.MeshLambertMaterial {
    const key = `${colour}:${going}`;
    let found = materials.get(key);
    if (!found) {
        found = new THREE.MeshLambertMaterial({
            color: colour,
            flatShading: true,
            transparent: going,
            opacity: going ? 0.55 : 1,
        });
        materials.set(key, found);
    }
    return found;
}

/**
 * A copy of a farm building, standing on the ground at its origin, about one
 * unit across, or null until loaded. A tint paints its red parts in a
 * faction's colour.
 */
export function farmBuilding(
    name: FarmModel,
    tint: string | null = null,
    going = false,
): THREE.Group | null {
    const template = ready.get(name);
    if (!template) {
        return null;
    }
    const model = template.clone();
    const dark = tint ? new THREE.Color(tint).multiplyScalar(0.7) : null;
    model.traverse((o) => {
        const mesh = o as THREE.Mesh;
        if (!mesh.isMesh) {
            return;
        }
        mesh.userData.shared = true;
        const list = (mesh.userData.materials ?? []) as {
            name: string;
            colour: string;
        }[];
        const colours = list.map(({ name: part, colour }) =>
            tint && /red/i.test(part)
                ? /dark/i.test(part) && dark
                    ? `#${dark.getHexString()}`
                    : tint
                : colour,
        );
        mesh.material =
            colours.length > 1
                ? colours.map((c) => material(c, going))
                : material(colours[0] ?? '#8a8399', going);
    });
    const g = new THREE.Group();
    g.add(model);
    return g;
}
