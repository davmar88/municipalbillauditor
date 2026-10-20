import * as THREE from 'three';

/**
 * A home on fire, and the ash it leaves. Flames are glowing sprites that
 * lick upwards and flicker, with embers and smoke rising off them; the ash
 * is a charred heap with fallen beams and a few embers still glowing. Both
 * are about one unit across, standing at their origin like the homes.
 */

let flameTexture: THREE.CanvasTexture | null = null;
let smokeTexture: THREE.CanvasTexture | null = null;

/** A teardrop of fire, white-hot at its root, fading to red at the tip. */
function flameMap(): THREE.CanvasTexture {
    if (flameTexture) {
        return flameTexture;
    }
    const c = document.createElement('canvas');
    c.width = 64;
    c.height = 128;
    const g = c.getContext('2d') as CanvasRenderingContext2D;
    const grad = g.createRadialGradient(32, 100, 2, 32, 84, 60);
    grad.addColorStop(0, 'rgba(255,250,220,1)');
    grad.addColorStop(0.25, 'rgba(255,200,80,0.95)');
    grad.addColorStop(0.55, 'rgba(255,110,20,0.75)');
    grad.addColorStop(0.85, 'rgba(200,40,10,0.25)');
    grad.addColorStop(1, 'rgba(120,20,0,0)');
    g.fillStyle = grad;
    g.beginPath();
    g.moveTo(32, 2);
    g.bezierCurveTo(48, 40, 62, 70, 58, 100);
    g.bezierCurveTo(54, 122, 10, 122, 6, 100);
    g.bezierCurveTo(2, 70, 16, 40, 32, 2);
    g.fill();
    flameTexture = new THREE.CanvasTexture(c);
    flameTexture.colorSpace = THREE.SRGBColorSpace;
    return flameTexture;
}

/** A soft round puff. */
function smokeMap(): THREE.CanvasTexture {
    if (smokeTexture) {
        return smokeTexture;
    }
    const c = document.createElement('canvas');
    c.width = 64;
    c.height = 64;
    const g = c.getContext('2d') as CanvasRenderingContext2D;
    const grad = g.createRadialGradient(32, 32, 2, 32, 32, 30);
    grad.addColorStop(0, 'rgba(255,255,255,0.9)');
    grad.addColorStop(0.6, 'rgba(255,255,255,0.35)');
    grad.addColorStop(1, 'rgba(255,255,255,0)');
    g.fillStyle = grad;
    g.fillRect(0, 0, 64, 64);
    smokeTexture = new THREE.CanvasTexture(c);
    smokeTexture.colorSpace = THREE.SRGBColorSpace;
    return smokeTexture;
}

/** A small number from a seed, so each fire flickers its own way but the same on every frame. */
function rand(seed: number): number {
    const x = Math.sin(seed * 127.1 + 311.7) * 43758.5453;
    return x - Math.floor(x);
}

type Lick = {
    sprite: THREE.Sprite;
    base: THREE.Vector3;
    size: number;
    phase: number;
    speed: number;
};

type Rising = {
    sprite: THREE.Sprite;
    x: number;
    z: number;
    phase: number;
    life: number;
    size: number;
    height: number;
};

/** Flames over a home, a unit across, `height` tall where the roof is. */
export function flames(seed: number, height = 1): THREE.Group {
    const group = new THREE.Group();
    group.userData.fire = true;
    const licks: Lick[] = [];
    const rising: Rising[] = [];
    const count = 14;
    for (let i = 0; i < count; i++) {
        const material = new THREE.SpriteMaterial({
            map: flameMap(),
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            transparent: true,
            color: new THREE.Color().setHSL(
                0.04 + rand(seed + i) * 0.06,
                1,
                0.55,
            ),
        });
        const sprite = new THREE.Sprite(material);
        sprite.center.set(0.5, 0.05);
        const angle = rand(seed + i * 3.1) * Math.PI * 2;
        const r = 0.15 + rand(seed + i * 7.3) * 0.38;
        // Some lick the walls, most rise off the roof.
        const y =
            i < 5
                ? rand(seed + i) * height * 0.4
                : height * (0.4 + rand(seed + i * 1.7) * 0.4);
        const base = new THREE.Vector3(
            Math.cos(angle) * r,
            y,
            Math.sin(angle) * r,
        );
        sprite.position.copy(base);
        const size = 0.2 + rand(seed + i * 5.9) * 0.28;
        licks.push({
            sprite,
            base,
            size,
            phase: rand(seed + i * 9.7) * Math.PI * 2,
            speed: 6 + rand(seed + i * 2.3) * 6,
        });
        group.add(sprite);
    }
    // Embers spark upwards; smoke rolls off above.
    for (let i = 0; i < 16; i++) {
        const ember = i < 10;
        const material = new THREE.SpriteMaterial({
            map: ember ? flameMap() : smokeMap(),
            blending: ember ? THREE.AdditiveBlending : THREE.NormalBlending,
            depthWrite: false,
            transparent: true,
            color: ember ? 0xffb347 : 0x3b3633,
        });
        const sprite = new THREE.Sprite(material);
        rising.push({
            sprite,
            x: (rand(seed + 40 + i) - 0.5) * 0.7,
            z: (rand(seed + 80 + i) - 0.5) * 0.7,
            phase: rand(seed + 120 + i),
            life: ember ? 1.4 + rand(seed + i) : 3 + rand(seed + i) * 2,
            size: ember ? 0.05 : 0.5 + rand(seed + 160 + i) * 0.4,
            height: ember ? height + 1.2 : height + 2.4,
        });
        group.add(sprite);
    }
    // A warm glow on the ground around it.
    const glow = new THREE.Mesh(
        new THREE.CircleGeometry(0.95, 24),
        new THREE.MeshBasicMaterial({
            color: 0xff7a1a,
            transparent: true,
            opacity: 0.35,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
        }),
    );
    glow.rotation.x = -Math.PI / 2;
    glow.position.y = 0.02;
    group.add(glow);
    group.userData.licks = licks;
    group.userData.rising = rising;
    group.userData.glow = glow;
    return group;
}

/** Move a fire on: flames stretch and flicker, embers and smoke rise and fade. */
export function animateFire(group: THREE.Group, now: number): void {
    const t = now / 1000;
    for (const lick of (group.userData.licks ?? []) as Lick[]) {
        const flicker =
            0.75 +
            0.25 * Math.sin(t * lick.speed + lick.phase) +
            0.12 * Math.sin(t * lick.speed * 2.7 + lick.phase * 2);
        lick.sprite.scale.set(
            lick.size * (0.8 + 0.2 * Math.sin(t * 5 + lick.phase)),
            lick.size * 1.5 * flicker,
            1,
        );
        lick.sprite.position.set(
            lick.base.x + Math.sin(t * 3 + lick.phase) * 0.04,
            lick.base.y,
            lick.base.z + Math.cos(t * 2.5 + lick.phase) * 0.04,
        );
        (lick.sprite.material as THREE.SpriteMaterial).opacity =
            0.7 + 0.3 * flicker;
    }
    for (const r of (group.userData.rising ?? []) as Rising[]) {
        const p = (t / r.life + r.phase) % 1;
        r.sprite.position.set(
            r.x * (1 + p * 0.6) + Math.sin(t * 2 + r.phase * 6) * 0.08 * p,
            0.4 + p * r.height,
            r.z * (1 + p * 0.6),
        );
        const grow = r.size * (r.size > 0.2 ? 0.6 + p * 1.6 : 1);
        r.sprite.scale.set(grow, grow, 1);
        (r.sprite.material as THREE.SpriteMaterial).opacity =
            (r.size > 0.2 ? 0.55 : 1) * Math.sin(p * Math.PI);
    }
    const glow = group.userData.glow as THREE.Mesh | undefined;
    if (glow) {
        (glow.material as THREE.MeshBasicMaterial).opacity =
            0.25 + 0.12 * Math.sin(t * 9) + 0.06 * Math.sin(t * 23);
    }
}

const charred = new THREE.MeshLambertMaterial({
    color: 0x2a2522,
    flatShading: true,
});
const ashGrey = new THREE.MeshLambertMaterial({
    color: 0x6f6a66,
    flatShading: true,
});
const ashPale = new THREE.MeshLambertMaterial({
    color: 0x9c9792,
    flatShading: true,
});

/** What a burned-down home leaves: a heap of ash, fallen beams, a chimney stump, embers and a thin smoke. */
export function ashes(seed: number): THREE.Group {
    const group = new THREE.Group();
    group.userData.fire = true;
    const heap = new THREE.Mesh(new THREE.CircleGeometry(0.62, 18), ashGrey);
    heap.rotation.x = -Math.PI / 2;
    heap.position.y = 0.015;
    group.add(heap);
    for (let i = 0; i < 9; i++) {
        const lump = new THREE.Mesh(
            new THREE.DodecahedronGeometry(0.08 + rand(seed + i) * 0.12, 0),
            i % 3 === 0 ? ashPale : ashGrey,
        );
        const a = rand(seed + i * 4.1) * Math.PI * 2;
        const r = rand(seed + i * 2.9) * 0.45;
        lump.position.set(Math.cos(a) * r, 0.02, Math.sin(a) * r);
        lump.scale.y = 0.45;
        group.add(lump);
    }
    // Beams that fell where the walls stood.
    for (let i = 0; i < 6; i++) {
        const beam = new THREE.Mesh(
            new THREE.BoxGeometry(0.3 + rand(seed + i) * 0.25, 0.04, 0.045),
            charred,
        );
        const a = rand(seed + i * 6.7) * Math.PI;
        beam.position.set(
            (rand(seed + i * 3.3) - 0.5) * 0.6,
            0.05 + rand(seed + i) * 0.08,
            (rand(seed + i * 8.1) - 0.5) * 0.6,
        );
        beam.rotation.set(
            (rand(seed + i * 1.1) - 0.5) * 0.2,
            a,
            (rand(seed + i * 1.9) - 0.5) * 0.25,
        );
        group.add(beam);
    }
    // A post or two still standing, and the chimney's stump.
    for (let i = 0; i < 2; i++) {
        const post = new THREE.Mesh(
            new THREE.BoxGeometry(0.045, 0.16 + i * 0.08, 0.045),
            charred,
        );
        post.position.set(
            i ? 0.38 : -0.35,
            (0.16 + i * 0.08) / 2,
            i ? -0.3 : 0.28,
        );
        post.rotation.z = (rand(seed + i) - 0.5) * 0.4;
        group.add(post);
    }
    const stump = new THREE.Mesh(
        new THREE.BoxGeometry(0.12, 0.16, 0.12),
        new THREE.MeshLambertMaterial({ color: 0x5b4f48, flatShading: true }),
    );
    stump.position.set(0.15, 0.08, 0.1);
    group.add(stump);
    // Embers still glowing in the heap, and a thin thread of smoke.
    const embers: THREE.Mesh[] = [];
    for (let i = 0; i < 6; i++) {
        const ember = new THREE.Mesh(
            new THREE.SphereGeometry(0.035, 6, 4),
            new THREE.MeshBasicMaterial({ color: 0xff5a1f, transparent: true }),
        );
        const a = rand(seed + 50 + i) * Math.PI * 2;
        const r = rand(seed + 60 + i) * 0.4;
        ember.position.set(Math.cos(a) * r, 0.05, Math.sin(a) * r);
        ember.userData.phase = rand(seed + 70 + i) * Math.PI * 2;
        embers.push(ember);
        group.add(ember);
    }
    const rising: Rising[] = [];
    for (let i = 0; i < 5; i++) {
        const sprite = new THREE.Sprite(
            new THREE.SpriteMaterial({
                map: smokeMap(),
                depthWrite: false,
                transparent: true,
                color: 0x7d7874,
            }),
        );
        rising.push({
            sprite,
            x: (rand(seed + 90 + i) - 0.5) * 0.3,
            z: (rand(seed + 95 + i) - 0.5) * 0.3,
            phase: i / 5,
            life: 5,
            size: 0.3,
            height: 1.8,
        });
        group.add(sprite);
    }
    group.userData.rising = rising;
    group.userData.embers = embers;
    return group;
}

/** Move the ash on: embers pulse and the smoke drifts up. */
export function animateAshes(group: THREE.Group, now: number): void {
    const t = now / 1000;
    for (const ember of (group.userData.embers ?? []) as THREE.Mesh[]) {
        (ember.material as THREE.MeshBasicMaterial).opacity =
            0.4 +
            0.6 *
                Math.abs(Math.sin(t * 1.3 + (ember.userData.phase as number)));
    }
    for (const r of (group.userData.rising ?? []) as Rising[]) {
        const p = (t / r.life + r.phase) % 1;
        r.sprite.position.set(
            r.x + Math.sin(t + r.phase * 6) * 0.1 * p,
            0.1 + p * r.height,
            r.z,
        );
        const grow = r.size * (0.5 + p * 1.5);
        r.sprite.scale.set(grow, grow, 1);
        (r.sprite.material as THREE.SpriteMaterial).opacity =
            0.35 * Math.sin(p * Math.PI);
    }
}
