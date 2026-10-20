import { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { dress } from '@/lib/animal-dress';
import type { Worn } from '@/lib/animal-dress';
import { speciesEmoji } from '@/lib/game';
import type { Species } from '@/types/game';
import { cn } from '@/lib/utils';

/** The moves a profile visitor can play, and each species' clip for it. */
const MOVES: { label: string; clips: string[]; only?: Species[] }[] = [
    { label: 'Idle', clips: ['Idle'] },
    { label: 'Walk', clips: ['Walk'] },
    { label: 'Eat', clips: ['Eating'] },
    { label: 'Fight', clips: ['Fighting'] },
    { label: 'Build', clips: ['Building'] },
    { label: 'Fly', clips: ['Fly'], only: ['raven'] },
];

/**
 * An animal in 3D, wearing the shop items it owns, turning when dragged. The
 * models live in public/models/animals: Quaternius's free animal pack (CC0),
 * with the goat, sheep, pig, hen and raven built from it in Blender
 * (scripts/blender).
 */
export default function AnimalStage({
    species,
    wearing,
    className,
}: {
    species: Species;
    wearing: Worn[];
    className?: string;
}) {
    const holder = useRef<HTMLDivElement>(null);
    const playRef = useRef<(clip: string[]) => void>(() => {});
    const [move, setMove] = useState('Idle');
    const [state, setState] = useState<'loading' | 'ready' | 'failed'>(
        'loading',
    );

    useEffect(() => {
        const box = holder.current;
        if (!box) {
            return;
        }
        let disposed = false;
        const renderer = new THREE.WebGLRenderer({
            antialias: true,
            alpha: true,
        });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.setClearColor(0x000000, 0);
        renderer.domElement.className = 'block h-full w-full';
        box.appendChild(renderer.domElement);

        const scene = new THREE.Scene();
        scene.add(new THREE.HemisphereLight('#ffffff', '#3a3550', 2.2));
        const sun = new THREE.DirectionalLight('#ffffff', 2.2);
        sun.position.set(4, 8, 6);
        scene.add(sun);
        const camera = new THREE.PerspectiveCamera(32, 1, 0.05, 100);
        const controls = new OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.enablePan = false;
        controls.enableZoom = false;
        controls.minPolarAngle = 0.9;
        controls.maxPolarAngle = 1.45;
        // Let the page scroll on phones; sideways drags turn the animal.
        renderer.domElement.style.touchAction = 'pan-y';

        // A soft shadow to stand on.
        const shadowCanvas = document.createElement('canvas');
        shadowCanvas.width = shadowCanvas.height = 128;
        const g = shadowCanvas.getContext('2d');
        if (g) {
            const grad = g.createRadialGradient(64, 64, 4, 64, 64, 64);
            grad.addColorStop(0, 'rgba(0,0,0,0.45)');
            grad.addColorStop(1, 'rgba(0,0,0,0)');
            g.fillStyle = grad;
            g.fillRect(0, 0, 128, 128);
        }
        const shadow = new THREE.Mesh(
            new THREE.PlaneGeometry(1, 1),
            new THREE.MeshBasicMaterial({
                map: new THREE.CanvasTexture(shadowCanvas),
                transparent: true,
                depthWrite: false,
            }),
        );
        shadow.rotation.x = -Math.PI / 2;
        scene.add(shadow);

        let mixer: THREE.AnimationMixer | null = null;
        let clips: THREE.AnimationClip[] = [];
        let current: THREE.AnimationAction | null = null;
        playRef.current = (names: string[]) => {
            if (!mixer) {
                return;
            }
            const clip =
                names
                    .map((n) => THREE.AnimationClip.findByName(clips, n))
                    .find(Boolean) ??
                THREE.AnimationClip.findByName(clips, 'Idle');
            if (!clip) {
                return;
            }
            const next = mixer.clipAction(clip);
            if (next === current) {
                return;
            }
            next.reset().fadeIn(0.25).play();
            current?.fadeOut(0.25);
            current = next;
        };

        new GLTFLoader().load(
            `/models/animals/${species}.glb?v=4`,
            (gltf) => {
                if (disposed) {
                    return;
                }
                const root = gltf.scene;
                scene.add(root);
                root.updateMatrixWorld(true);
                // Every animal at the same height, standing on the floor.
                const first = new THREE.Box3().setFromObject(root);
                root.scale.multiplyScalar(
                    1.6 / Math.max(0.01, first.max.y - first.min.y),
                );
                root.updateMatrixWorld(true);
                const placed = new THREE.Box3().setFromObject(root);
                root.position.y -= placed.min.y;
                root.updateMatrixWorld(true);
                const bounds = new THREE.Box3().setFromObject(root);
                const size = bounds.getSize(new THREE.Vector3()).length();
                const meshes: THREE.SkinnedMesh[] = [];
                root.traverse((o) => {
                    if ((o as THREE.SkinnedMesh).isSkinnedMesh) {
                        meshes.push(o as THREE.SkinnedMesh);
                    }
                });
                const worn = dress(scene, root, meshes, size, wearing);

                // Frame the animal and everything it wears, a banner pole included.
                const all = new THREE.Box3().setFromObject(root);
                worn.forEach((o) => all.expandByObject(o));
                const extent = all.getSize(new THREE.Vector3());
                const centre = all.getCenter(new THREE.Vector3());
                const tanV = Math.tan(THREE.MathUtils.degToRad(camera.fov / 2));
                const across = Math.max(extent.x, extent.z);
                const distance =
                    Math.max(
                        extent.y / 2 / tanV,
                        across / 2 / (tanV * Math.max(1, camera.aspect)),
                    ) *
                        1.05 +
                    across / 3;
                shadow.scale.setScalar(size * 0.7);
                controls.target.copy(centre);
                camera.position
                    .copy(centre)
                    .addScaledVector(
                        new THREE.Vector3(0.55, 0.3, 0.78).normalize(),
                        distance,
                    );
                controls.update();

                mixer = new THREE.AnimationMixer(root);
                clips = gltf.animations;
                playRef.current(['Idle']);
                setState('ready');
            },
            undefined,
            () => !disposed && setState('failed'),
        );

        const fit = () => {
            const { clientWidth: w, clientHeight: h } = box;
            renderer.setSize(w, h, false);
            camera.aspect = w / Math.max(1, h);
            camera.updateProjectionMatrix();
        };
        const observer = new ResizeObserver(fit);
        observer.observe(box);
        fit();

        let last = performance.now();
        renderer.setAnimationLoop((now: number) => {
            mixer?.update(Math.min(0.05, (now - last) / 1000));
            last = now;
            controls.update();
            renderer.render(scene, camera);
        });

        return () => {
            disposed = true;
            observer.disconnect();
            renderer.setAnimationLoop(null);
            controls.dispose();
            scene.traverse((o) => {
                const mesh = o as THREE.Mesh;
                mesh.geometry?.dispose();
                const mats = Array.isArray(mesh.material)
                    ? mesh.material
                    : mesh.material
                      ? [mesh.material]
                      : [];
                mats.forEach((m) => m.dispose());
            });
            renderer.dispose();
            renderer.domElement.remove();
        };
    }, [species, wearing]);

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <div
                ref={holder}
                className="relative h-64 w-full cursor-grab overflow-hidden rounded-xl border bg-card active:cursor-grabbing sm:h-72"
            >
                {state !== 'ready' && (
                    <span
                        className="absolute inset-0 flex items-center justify-center text-6xl"
                        aria-hidden
                    >
                        {speciesEmoji(species)}
                    </span>
                )}
            </div>
            {state === 'ready' && (
                <div
                    className="flex flex-wrap gap-1.5"
                    role="group"
                    aria-label="Moves"
                >
                    {MOVES.filter(
                        (m) => !m.only || m.only.includes(species),
                    ).map((m) => (
                        <button
                            key={m.label}
                            type="button"
                            onClick={() => {
                                setMove(m.label);
                                playRef.current(m.clips);
                            }}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-semibold',
                                move === m.label
                                    ? 'border-primary text-primary'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {m.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
