import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { CITY_MODELS } from '@/lib/city-lots';
import type { CityModel, Lot } from '@/lib/city-lots';

/**
 * Ironfield's town: streets of the humans' houses, shops and tenements on
 * its grey blocks, from the "Iron fields" building pack. Drawn instanced,
 * one batch per building, so hundreds cost little. The names over each
 * block are kept clear.
 */

/** Paces per model unit: a house about 2.2 units wide becomes about 55 paces. */
const SCALE = 25;

type Part = {
    geometry: THREE.BufferGeometry;
    material: THREE.Material | THREE.Material[];
    matrix: THREE.Matrix4;
};

const loader = new GLTFLoader();
let parts: Promise<Map<CityModel, Part[]>> | null = null;

const flat = new Map<string, THREE.MeshLambertMaterial>();

/** The pack's colours, flat-shaded like the rest of the map. */
function lambert(m: THREE.Material): THREE.MeshLambertMaterial {
    const colour = `#${((m as THREE.MeshStandardMaterial).color ?? new THREE.Color('#9a9aa2')).getHexString()}`;
    let found = flat.get(colour);
    if (!found) {
        found = new THREE.MeshLambertMaterial({
            color: colour,
            flatShading: true,
        });
        flat.set(colour, found);
    }
    return found;
}

/** Download every building once: each one's meshes, ready to be instanced. */
export function loadCity(): Promise<Map<CityModel, Part[]>> {
    parts ??= Promise.all(
        CITY_MODELS.map((name) =>
            loader.loadAsync(`/models/city/${name}.glb`).then((gltf) => {
                gltf.scene.updateMatrixWorld(true);
                const list: Part[] = [];
                gltf.scene.traverse((o) => {
                    const mesh = o as THREE.Mesh;
                    if (mesh.isMesh) {
                        list.push({
                            geometry: mesh.geometry,
                            material: Array.isArray(mesh.material)
                                ? mesh.material.map(lambert)
                                : lambert(mesh.material),
                            matrix: mesh.matrixWorld.clone(),
                        });
                    }
                });
                return [name, list] as const;
            }),
        ),
    ).then((all) => new Map(all));
    parts.catch(() => {
        parts = null;
    });
    return parts;
}

/** The town, one instanced batch per building and part. */
export function buildCity(
    group: THREE.Group,
    lots: Lot[],
    models: Map<CityModel, Part[]>,
) {
    const byModel = new Map<CityModel, Lot[]>();
    for (const lot of lots) {
        byModel.set(lot.model, [...(byModel.get(lot.model) ?? []), lot]);
    }
    const place = new THREE.Matrix4();
    const turn = new THREE.Matrix4();
    const size = new THREE.Matrix4().makeScale(SCALE, SCALE, SCALE);
    for (const [name, list] of byModel) {
        for (const part of models.get(name) ?? []) {
            const batch = new THREE.InstancedMesh(
                part.geometry,
                part.material,
                list.length,
            );
            list.forEach((lot, i) => {
                place.makeTranslation(lot.x, 0, lot.y);
                turn.makeRotationY(lot.turn);
                batch.setMatrixAt(
                    i,
                    place
                        .clone()
                        .multiply(turn)
                        .multiply(size)
                        .multiply(part.matrix),
                );
            });
            batch.instanceMatrix.needsUpdate = true;
            batch.computeBoundingSphere();
            batch.userData.shared = true;
            group.add(batch);
        }
    }
}
