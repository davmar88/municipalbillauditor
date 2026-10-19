import * as THREE from 'three';
import type { Point } from '@/lib/hollowmere';
import { FARM, LANDS, WORLD } from '@/lib/hollowmere';
import type { Size } from '@/components/game/world-map';
import { zoomLimits } from '@/components/game/world-map';

const FOV = 36;

/**
 * How far the camera looks down (0 is the horizon, 90 straight down): almost
 * overhead when the whole valley shows, leaning back as you come in close.
 */
export function pitchFor(k: number) {
    const t = Math.min(1, Math.max(0, Math.log(k / 0.2) / Math.log(1.2 / 0.2)));
    return THREE.MathUtils.degToRad(68 - t * 16);
}

type Box = { x: number; y: number; width: number; height: number };

const ease = (t: number) =>
    t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2;

/**
 * The 3D map's camera, held the way the 2D map holds its view: a ground point
 * it looks at (map x and y; y is south) and k, screen pixels per pace there.
 * North stays up. Everything that sizes itself by zoom reads k.
 */
export class Rig {
    readonly camera = new THREE.PerspectiveCamera(FOV, 1, 5, 40000);
    target: Point = [FARM.x + FARM.width / 2, FARM.y + FARM.height / 2];
    k = 0.6;
    size: Size = { width: 1, height: 1 };
    private flight: {
        from: [Point, number];
        to: [Point, number];
        start: number;
        ms: number;
    } | null = null;
    private velocity: Point | null = null;
    private lastGlide = 0;
    private readonly ground = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
    private readonly ray = new THREE.Raycaster();
    /** The zooms that show the whole farm and the whole valley, at a slant. */
    private fits = { farm: 0.6, all: 0.2 };

    resize(size: Size) {
        this.size = size;
        this.camera.aspect = size.width / Math.max(1, size.height);
        this.camera.updateProjectionMatrix();
        this.fits = {
            farm: this.framing(FARM)[1],
            all: this.framing(LANDS)[1],
        };
        this.set(this.target, this.k);
    }

    /**
     * Zoom range: in to 3 pixels a pace, out to a little past the whole
     * valley. As on the 2D map, but measured with the tilt, which shows the
     * near (south) ground bigger than the far.
     */
    limits() {
        const { max } = zoomLimits(this.size);
        return {
            fit: this.fits.farm,
            all: this.fits.all,
            min: this.fits.all * 0.95,
            max,
        };
    }

    /** Where to look, and how close, so a whole box of the map shows, centred. */
    framing(box: Box): [Point, number] {
        const keep: [Point, number] = [this.target, this.k];
        const { width, height } = this.size;
        let target: Point = [box.x + box.width / 2, box.y + box.height / 2];
        let k = Math.min(width / box.width, height / box.height);
        // The tilt changes with zoom, so settle it in a few steps.
        for (let i = 0; i < 4; i++) {
            this.place(target, k);
            const corners = (
                [
                    [box.x, box.y],
                    [box.x + box.width, box.y],
                    [box.x, box.y + box.height],
                    [box.x + box.width, box.y + box.height],
                ] as Point[]
            ).map((corner) => this.project(corner));
            const xs = corners.map((c) => c[0]);
            const ys = corners.map((c) => c[1]);
            const middle = this.pick([
                (Math.min(...xs) + Math.max(...xs)) / 2,
                (Math.min(...ys) + Math.max(...ys)) / 2,
            ]);
            if (middle) {
                target = middle;
            }
            k *= Math.min(
                width / (Math.max(...xs) - Math.min(...xs)),
                height / (Math.max(...ys) - Math.min(...ys)),
            );
        }
        this.place(keep[0], keep[1]);
        return [target, k];
    }

    /** How far the camera looks down now, in radians. */
    get pitch() {
        return pitchFor(this.k);
    }

    /** Camera distance that gives k pixels per pace at the target. */
    distance(k = this.k) {
        return (
            this.size.height /
            (2 * k * Math.tan(THREE.MathUtils.degToRad(FOV / 2)))
        );
    }

    /** Keep the zoom in range and the target over the world. */
    clamp(target: Point, k: number): [Point, number] {
        const { min, max } = this.limits();
        const z = Math.min(max, Math.max(min, k));
        return [
            [
                Math.min(WORLD.x + WORLD.width, Math.max(WORLD.x, target[0])),
                Math.min(WORLD.y + WORLD.height, Math.max(WORLD.y, target[1])),
            ],
            z,
        ];
    }

    set(target: Point, k: number) {
        const [t, z] = this.clamp(target, k);
        this.place(t, z);
    }

    /** Put the camera at a view as it is, in or out of range. */
    private place(target: Point, k: number) {
        this.target = target;
        this.k = k;
        const d = this.distance(this.k);
        const pitch = pitchFor(this.k);
        this.camera.position.set(
            this.target[0],
            Math.sin(pitch) * d,
            this.target[1] + Math.cos(pitch) * d,
        );
        this.camera.lookAt(this.target[0], 0, this.target[1]);
        this.camera.updateMatrixWorld();
    }

    /** The map point under a screen point, or null above the horizon. */
    pick(screen: Point): Point | null {
        const ndc = new THREE.Vector2(
            (screen[0] / this.size.width) * 2 - 1,
            -(screen[1] / this.size.height) * 2 + 1,
        );
        this.ray.setFromCamera(ndc, this.camera);
        const hit = new THREE.Vector3();
        return this.ray.ray.intersectPlane(this.ground, hit)
            ? [hit.x, hit.z]
            : null;
    }

    /** Where a map point shows on screen. */
    project(point: Point, height = 0): Point {
        const v = new THREE.Vector3(point[0], height, point[1]).project(
            this.camera,
        );
        return [
            ((v.x + 1) / 2) * this.size.width,
            ((1 - v.y) / 2) * this.size.height,
        ];
    }

    /** Move so that the map point under `screen` lands under `to` (a drag). */
    keepUnder(anchor: Point, screen: Point) {
        const now = this.pick(screen);
        if (!now) {
            return;
        }
        this.set(
            [
                this.target[0] + anchor[0] - now[0],
                this.target[1] + anchor[1] - now[1],
            ],
            this.k,
        );
    }

    /** Zoom by factor about a screen point, keeping the ground under it still. */
    zoomAt(screen: Point, factor: number, animate = false) {
        const anchor = this.pick(screen);
        const k = this.clamp(this.target, this.k * factor)[1];
        if (!anchor) {
            return this.go(this.target, k, animate ? 280 : 0);
        }
        // Work out where the target ends up, then fly or jump there.
        const keep: [Point, number] = [this.target, this.k];
        this.set(this.target, k);
        this.keepUnder(anchor, screen);
        const end: [Point, number] = [this.target, this.k];
        this.set(keep[0], keep[1]);
        this.go(end[0], end[1], animate ? 280 : 0);
    }

    /** Put a map point at a spot on screen (the middle unless told), at zoom k. */
    lookAt(point: Point, k = this.k, spot?: Point, ms = 480) {
        const keep: [Point, number] = [this.target, this.k];
        this.set(point, k);
        if (spot) {
            const at = this.pick(spot);
            if (at) {
                this.set(
                    [
                        this.target[0] + (point[0] - at[0]),
                        this.target[1] + (point[1] - at[1]),
                    ],
                    this.k,
                );
            }
        }
        const end: [Point, number] = [this.target, this.k];
        this.set(keep[0], keep[1]);
        this.go(end[0], end[1], ms);
    }

    go(target: Point, k: number, ms: number) {
        this.stop();
        const to = this.clamp(target, k);
        if (ms <= 0) {
            this.set(to[0], to[1]);
            return;
        }
        this.flight = {
            from: [this.target, this.k],
            to,
            start: performance.now(),
            ms,
        };
    }

    stop() {
        this.flight = null;
        this.velocity = null;
    }

    /** Carry on sliding after a flick, in map units per ms. */
    glide(velocity: Point) {
        this.flight = null;
        this.velocity = velocity;
        this.lastGlide = performance.now();
    }

    get moving() {
        return this.flight !== null || this.velocity !== null;
    }

    /** Advance a flight or a glide; true while the camera moved this frame. */
    tick(now: number): boolean {
        if (this.flight) {
            const { from, to, start, ms } = this.flight;
            const t = Math.min(1, (now - start) / ms);
            const e = ease(t);
            const k = Math.exp(
                Math.log(from[1]) + (Math.log(to[1]) - Math.log(from[1])) * e,
            );
            this.set(
                [
                    from[0][0] + (to[0][0] - from[0][0]) * e,
                    from[0][1] + (to[0][1] - from[0][1]) * e,
                ],
                k,
            );
            if (t >= 1) {
                this.flight = null;
            }
            return true;
        }
        if (this.velocity) {
            const dt = Math.min(40, now - this.lastGlide);
            this.lastGlide = now;
            const [vx, vy] = this.velocity;
            this.set(
                [this.target[0] + vx * dt, this.target[1] + vy * dt],
                this.k,
            );
            const decay = 0.993 ** dt;
            this.velocity = [vx * decay, vy * decay];
            // Stop below 0.02 screen px per ms, as the 2D map does.
            if (Math.hypot(vx, vy) * this.k < 0.02) {
                this.velocity = null;
            }
            return true;
        }
        return false;
    }
}
