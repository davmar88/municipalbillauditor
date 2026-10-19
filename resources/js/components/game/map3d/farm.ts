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

/** Houses and more from Quaternius's LowPoly Medieval Village Pack (CC0), fetched only when a home or a tent needs one. */
export const MEDIEVAL_MODELS = [
    'House_1',
    'House_2',
    'House_3',
    'House_4',
    'Inn',
    'Blacksmith',
    'Stable',
    'Mill',
    'Sawmill',
    'Bell_Tower',
    'Bonfire_Lit',
] as const;

export type FarmModel =
    | (typeof FARM_MODELS)[number]
    | (typeof MEDIEVAL_MODELS)[number];

/** The buildings a home can look like, by the style its owner picked. */
export const HOME_STYLE: Record<string, FarmModel> = {
    barn: 'SmallBarn',
    coop: 'ChickenCoop',
    silo: 'Silo_House',
    windmill: 'Windmill',
    water_tower: 'WaterTower',
    timber_house: 'House_1',
    town_house: 'House_2',
    stone_cottage: 'House_3',
    low_cottage: 'House_4',
    inn: 'Inn',
    forge: 'Blacksmith',
    stable: 'Stable',
    mill_house: 'Mill',
    sawmill: 'Sawmill',
    bell_tower: 'Bell_Tower',
};

/** Which building stands for each kind a faction can raise. */
export const FOR_KIND: Record<BuildingKindValue, FarmModel> = {
    den: 'SmallBarn',
    watchtower: 'WaterTower',
    drill_yard: 'OpenBarn',
    storehouse: 'Silo_House',
    workshop: 'Barn',
    head_office: 'BigBarn',
    mill: 'Windmill',
    well: 'Well',
    coop: 'ChickenCoop',
    silo: 'Silo',
};

const loader = new GLTFLoader();
const loads = new Map<FarmModel, Promise<void>>();
const ready = new Map<FarmModel, THREE.Object3D>();

function folder(name: FarmModel): string {
    return (FARM_MODELS as readonly string[]).includes(name)
        ? 'farm'
        : 'medieval';
}

/** Download these buildings, each once; resolves when they can be placed. */
export function loadModels(names: readonly FarmModel[]): Promise<void> {
    return Promise.all(
        names.map((name) => {
            let found = loads.get(name);
            if (!found) {
                found = loader
                    .loadAsync(`/models/${folder(name)}/${name}.glb`)
                    .then((gltf) => {
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
                    });
                loads.set(name, found);
                // A failed download can be tried again later.
                found.catch(() => loads.delete(name));
            }
            return found;
        }),
    ).then(() => undefined);
}

/** Download the farm buildings once; resolves when they can be placed. */
export function loadFarm(): Promise<void> {
    return loadModels(FARM_MODELS);
}

/** Whether these buildings have all loaded. */
export function modelsReady(names: readonly FarmModel[]): boolean {
    return names.every((name) => ready.has(name));
}

export function farmReady(): boolean {
    return modelsReady(FARM_MODELS);
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
