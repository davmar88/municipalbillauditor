import * as THREE from 'three';
import type { GLTF } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { clone as cloneSkinned } from 'three/examples/jsm/utils/SkeletonUtils.js';
import { dress } from '@/lib/animal-dress';
import type { Species, Worn } from '@/types/game';

/** One download per species, shared by every animal of that kind on the map. */
const cache = new Map<Species, Promise<GLTF>>();
const loader = new GLTFLoader();

function load(species: Species): Promise<GLTF> {
    let found = cache.get(species);
    if (!found) {
        found = loader.loadAsync(`/models/animals/${species}.glb?v=2`);
        cache.set(species, found);
        // A failed download can be tried again later.
        found.catch(() => cache.delete(species));
    }
    return found;
}

/**
 * An animal on the map: its own copy of the species model, standing on the
 * ground at its origin, 1 unit tall, facing north, dressed in what it wears.
 * Scale it to the size it should show at.
 */
export type Actor = {
    root: THREE.Group;
    mixer: THREE.AnimationMixer;
    play: (names: string[], fade?: number) => void;
    dispose: () => void;
};

export async function makeActor(
    species: Species,
    wearing: Worn[],
    seed: number,
): Promise<Actor> {
    const gltf = await load(species);
    const model = cloneSkinned(gltf.scene) as THREE.Object3D;
    const meshes: THREE.SkinnedMesh[] = [];
    model.traverse((o) => {
        if ((o as THREE.SkinnedMesh).isSkinnedMesh) {
            meshes.push(o as THREE.SkinnedMesh);
        }
    });
    // The model's parts (one per colour) share one skeleton, as in the file;
    // cloning gives each its own, which would be updated and uploaded once per
    // part. Rebind before the first world update, which sets each part's bind
    // inverse.
    const skeleton = meshes[0]?.skeleton ?? null;
    for (const mesh of meshes) {
        if (skeleton && mesh.skeleton !== skeleton) {
            mesh.bind(skeleton, mesh.bindMatrix);
        }
    }
    const holder = new THREE.Group();
    // A scratch scene for dressing: the items attach to bones in world space.
    const stage = new THREE.Scene();
    stage.add(model);
    model.updateMatrixWorld(true);
    const first = new THREE.Box3().setFromObject(model);
    model.scale.multiplyScalar(1.6 / Math.max(0.01, first.max.y - first.min.y));
    model.updateMatrixWorld(true);
    const placed = new THREE.Box3().setFromObject(model);
    model.position.y -= placed.min.y;
    const centre = placed.getCenter(new THREE.Vector3());
    model.position.x -= centre.x;
    model.position.z -= centre.z;
    model.updateMatrixWorld(true);
    for (const mesh of meshes) {
        // Off screen, skip drawing it; the bounds are roomy because it moves.
        mesh.computeBoundingSphere();
        mesh.boundingSphere!.radius *= 1.6;
    }
    const size = new THREE.Box3()
        .setFromObject(model)
        .getSize(new THREE.Vector3())
        .length();
    dress(stage, model, meshes, size, wearing);
    stage.remove(model);

    // The pack faces +Z (south); the map wants its animals to face the way they walk,
    // which the caller sets with root.rotation.y. Height 1.6 becomes 1.
    const inner = new THREE.Group();
    inner.scale.setScalar(1 / 1.6);
    inner.add(model);
    holder.add(inner);

    const mixer = new THREE.AnimationMixer(model);
    let current: THREE.AnimationAction | null = null;
    const play = (names: string[], fade = 0.25) => {
        const clip =
            names
                .map((n) => THREE.AnimationClip.findByName(gltf.animations, n))
                .find(Boolean) ??
            THREE.AnimationClip.findByName(gltf.animations, 'Idle');
        if (!clip) {
            return;
        }
        const next = mixer.clipAction(clip);
        if (next === current) {
            return;
        }
        next.reset().fadeIn(fade).play();
        // Animals standing together shouldn't move in step.
        next.time = ((seed % 97) / 97) * clip.duration;
        current?.fadeOut(fade);
        current = next;
    };

    return {
        root: holder,
        mixer,
        play,
        dispose: () => {
            mixer.stopAllAction();
            mixer.uncacheRoot(model);
            skeleton?.dispose();
            holder.traverse((o) => {
                const mesh = o as THREE.Mesh;
                // Shared geometry belongs to the cached model; only items made here are ours.
                if (
                    mesh.isMesh &&
                    !(mesh as unknown as THREE.SkinnedMesh).isSkinnedMesh
                ) {
                    mesh.geometry?.dispose();
                    const mats = Array.isArray(mesh.material)
                        ? mesh.material
                        : [mesh.material];
                    mats.forEach((m) => m?.dispose());
                }
            });
        },
    };
}
