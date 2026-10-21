import { useEffect, useRef } from 'react';
import * as THREE from 'three';
import type { Actor } from '@/components/game/map3d/models';
import { makeActor } from '@/components/game/map3d/models';
import type { Species } from '@/types/game';

/** The moves an animal tries on its card, now and then, when its model has them. */
const MOVES = [
    'Idle',
    'Idle_2',
    'Idle_2_HeadLow',
    'Eating',
    'Idle_HitReact1',
    'Jump_ToIdle',
    'Attack',
    'Fighting',
    'Building',
    'Walk',
    'Gallop',
];

type View = {
    scene: THREE.Scene;
    camera: THREE.PerspectiveCamera;
    actor: Actor | null;
    moves: string[];
    next: number;
};

/**
 * The 3D animals on the start page's animal cards, each moving at random. One canvas over
 * the page draws every box marked `data-animal-view="<species>"` inside
 * `within`, so twenty animals share one WebGL context.
 */
export default function AnimalCardStage({
    within,
}: {
    within: React.RefObject<HTMLElement | null>;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        let renderer: THREE.WebGLRenderer;

        try {
            renderer = new THREE.WebGLRenderer({
                canvas,
                alpha: true,
                antialias: true,
            });
        } catch {
            // No WebGL: the cards read fine without their animals.
            return;
        }

        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.setClearColor(0x000000, 0);
        renderer.setScissorTest(true);

        const views = new Map<Element, View>();
        let gone = false;
        let seed = 1;

        const choose = (view: View, now: number) => {
            const move =
                view.moves[Math.floor(Math.random() * view.moves.length)] ??
                'Idle';
            view.actor?.play([move], 0.35);
            view.next = now + 2500 + Math.random() * 3500;
        };

        const viewFor = (element: Element): View => {
            let view = views.get(element);

            if (view) {
                return view;
            }

            const scene = new THREE.Scene();
            scene.add(new THREE.HemisphereLight(0xffffff, 0x445566, 2.2));
            const sun = new THREE.DirectionalLight(0xffffff, 1.6);
            sun.position.set(2, 4, 3);
            scene.add(sun);
            const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 20);
            camera.position.set(1.4, 0.95, 2.3);
            camera.lookAt(0, 0.45, 0);
            const made: View = {
                scene,
                camera,
                actor: null,
                moves: [],
                next: 0,
            };
            views.set(element, made);
            const species = element.getAttribute('data-animal-view') as Species;
            makeActor(species, [], seed++ * 31)
                .then((actor) => {
                    if (gone) {
                        actor.dispose();

                        return;
                    }

                    // A three-quarter view, the animal filling its box.
                    actor.root.rotation.y = 0.9;
                    scene.add(actor.root);
                    actor.root.updateMatrixWorld(true);
                    const bounds = new THREE.Box3().setFromObject(actor.root);
                    const middle = bounds.getCenter(new THREE.Vector3());
                    const reach = bounds.getSize(new THREE.Vector3()).length();
                    const away =
                        (reach / 2 / Math.tan((camera.fov * Math.PI) / 360)) *
                        1.05;
                    camera.position
                        .set(0.35, 0.3, 1)
                        .normalize()
                        .multiplyScalar(away)
                        .add(middle);
                    camera.lookAt(middle);
                    made.actor = actor;
                    made.moves = MOVES.filter((move) =>
                        actor.clips.includes(move),
                    );
                    choose(made, performance.now());
                })
                .catch(() => {
                    // A model that won't load leaves its box empty.
                });
            view = made;

            return view;
        };

        const clock = new THREE.Clock();
        let frame = 0;

        const draw = () => {
            frame = requestAnimationFrame(draw);
            const dt = Math.min(clock.getDelta(), 0.1);
            const now = performance.now();
            const width = canvas.clientWidth;
            const height = canvas.clientHeight;
            const ratio = renderer.getPixelRatio();

            if (
                canvas.width !== Math.floor(width * ratio) ||
                canvas.height !== Math.floor(height * ratio)
            ) {
                renderer.setSize(width, height, false);
            }

            renderer.setScissor(0, 0, width, height);
            renderer.clear();

            within.current
                ?.querySelectorAll('[data-animal-view]')
                .forEach((element) => {
                    const box = element.getBoundingClientRect();

                    // Only the boxes on screen are moved and drawn.
                    if (
                        box.width === 0 ||
                        box.bottom < 0 ||
                        box.top > height ||
                        box.right < 0 ||
                        box.left > width
                    ) {
                        return;
                    }

                    const view = viewFor(element);

                    if (!view.actor) {
                        return;
                    }

                    if (now > view.next) {
                        choose(view, now);
                    }

                    view.actor.mixer.update(dt);
                    view.camera.aspect = box.width / box.height;
                    view.camera.updateProjectionMatrix();
                    const bottom = height - box.bottom;
                    renderer.setViewport(
                        box.left,
                        bottom,
                        box.width,
                        box.height,
                    );
                    renderer.setScissor(
                        box.left,
                        bottom,
                        box.width,
                        box.height,
                    );
                    renderer.render(view.scene, view.camera);
                });
        };

        frame = requestAnimationFrame(draw);

        return () => {
            gone = true;
            cancelAnimationFrame(frame);
            views.forEach((view) => view.actor?.dispose());
            renderer.dispose();
        };
    }, [within]);

    return (
        <canvas
            ref={canvasRef}
            aria-hidden
            className="pointer-events-none fixed inset-0 z-10 h-screen w-screen"
        />
    );
}
