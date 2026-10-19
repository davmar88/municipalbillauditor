import * as THREE from 'three';
import type { Worn } from '@/types/game';

export type { Worn };

type Sample = { p: THREE.Vector3; n: THREE.Vector3 };

const WOOD = '#8b6b4a';
const METAL = '#a7a2b3';
const UP = new THREE.Vector3(0, 1, 0);
/** A hat's brim radius, as a share of the head's width. */
const HAT_SIZE = 0.62;

/** three.js drops the dots from node names, so the model's "Ear1.L" is the bone "Ear1L". */
const boneName = (name: string) => THREE.PropertyBinding.sanitizeNodeName(name);

/**
 * Rest-pose world positions and normals of the vertices that move mostly with
 * the given bones. A model split by material arrives as several skinned
 * meshes, so this looks through all of them.
 */
function samples(meshes: THREE.SkinnedMesh[], bones: string[]): Sample[] {
    const out: Sample[] = [];
    for (const mesh of meshes) {
        const list = mesh.skeleton.bones;
        const wanted = new Set(
            bones
                .map((n) => list.findIndex((b) => b.name === boneName(n)))
                .filter((i) => i >= 0),
        );
        if (wanted.size === 0) {
            continue;
        }
        const geo = mesh.geometry;
        const si = geo.attributes.skinIndex;
        const sw = geo.attributes.skinWeight;
        const nr = geo.attributes.normal;
        const normalMatrix = new THREE.Matrix3().getNormalMatrix(
            mesh.matrixWorld,
        );
        for (let i = 0; i < geo.attributes.position.count; i++) {
            let best = 0;
            let bestW = -1;
            for (let k = 0; k < 4; k++) {
                const w = sw.getComponent(i, k);
                if (w > bestW) {
                    bestW = w;
                    best = si.getComponent(i, k);
                }
            }
            if (!wanted.has(best)) {
                continue;
            }
            const p = mesh
                .getVertexPosition(i, new THREE.Vector3())
                .applyMatrix4(mesh.matrixWorld);
            const n = nr
                ? new THREE.Vector3()
                      .fromBufferAttribute(nr, i)
                      .applyMatrix3(normalMatrix)
                      .normalize()
                : UP.clone();
            out.push({ p, n });
        }
    }
    return out;
}

const points = (meshes: THREE.SkinnedMesh[], bones: string[]) =>
    samples(meshes, bones).map((s) => s.p);

const boxOf = (pts: THREE.Vector3[]) => new THREE.Box3().setFromPoints(pts);

const centroid = (pts: THREE.Vector3[]) =>
    pts
        .reduce((a, q) => a.add(q), new THREE.Vector3())
        .multiplyScalar(1 / Math.max(1, pts.length));

// ---- materials --------------------------------------------------------------------

const textures = new Map<string, THREE.CanvasTexture>();

/** The maker's pattern in their two colours, drawn once and shared. */
function patternTexture(
    main: string,
    second: string,
    pattern: string,
): THREE.CanvasTexture | null {
    if (pattern === 'plain' || typeof document === 'undefined') {
        return null;
    }
    const key = `${main}-${second}-${pattern}`;
    const cached = textures.get(key);
    if (cached) {
        return cached;
    }
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 64;
    const g = canvas.getContext('2d');
    if (!g) {
        return null;
    }
    g.fillStyle = main;
    g.fillRect(0, 0, 64, 64);
    g.fillStyle = second;
    if (pattern === 'stripes') {
        for (let x = -64; x < 128; x += 16) {
            g.beginPath();
            g.moveTo(x, 0);
            g.lineTo(x + 6, 0);
            g.lineTo(x + 70, 64);
            g.lineTo(x + 64, 64);
            g.fill();
        }
    } else if (pattern === 'dots') {
        for (const [x, y] of [
            [16, 16],
            [48, 48],
            [48, 16],
            [16, 48],
        ]) {
            g.beginPath();
            g.arc(x, y, 6, 0, Math.PI * 2);
            g.fill();
        }
    } else if (pattern === 'checks') {
        g.fillRect(0, 0, 32, 32);
        g.fillRect(32, 32, 32, 32);
    } else if (pattern === 'stars') {
        for (const [cx, cy] of [
            [16, 16],
            [48, 48],
        ]) {
            g.beginPath();
            for (let k = 0; k < 10; k++) {
                const r = k % 2 === 0 ? 10 : 4;
                const a = -Math.PI / 2 + (k * Math.PI) / 5;
                g.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
            }
            g.closePath();
            g.fill();
        }
    }
    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = texture.wrapT = THREE.RepeatWrapping;
    texture.colorSpace = THREE.SRGBColorSpace;
    textures.set(key, texture);
    return texture;
}

function solid(
    colour: string,
    extra: THREE.MeshStandardMaterialParameters = {},
) {
    return new THREE.MeshStandardMaterial({
        color: colour,
        roughness: 0.75,
        flatShading: true,
        ...extra,
    });
}

/** The item's main colour, with its pattern laid over it when it has one. */
function fabric(item: Worn, repeat: [number, number], double = false) {
    const [main = '#b39aff', second = '#41d1ff'] = item.colours;
    const source = patternTexture(main, second, item.pattern);
    const side = double ? THREE.DoubleSide : THREE.FrontSide;
    if (!source) {
        return solid(main, { side });
    }
    const map = source.clone();
    map.repeat.set(repeat[0], repeat[1]);
    map.needsUpdate = true;
    return new THREE.MeshStandardMaterial({
        color: '#ffffff',
        map,
        roughness: 0.75,
        flatShading: true,
        side,
    });
}

const trimOf = (item: Worn) => item.colours[1] ?? item.colours[0] ?? '#41d1ff';

// ---- dressing ------------------------------------------------------------------------

/**
 * Builds each worn item in world space on the model's rest pose, sized from
 * the vertices of the bones it sits on, then hands it to its bone so it moves
 * with every animation. Call it before any animation plays. Returns what it
 * made, so the caller can dispose of it.
 */
export function dress(
    scene: THREE.Object3D,
    root: THREE.Object3D,
    meshes: THREE.SkinnedMesh[],
    size: number,
    wearing: Worn[],
): THREE.Object3D[] {
    const made: THREE.Object3D[] = [];
    const has = (name: string) => !!root.getObjectByName(boneName(name));
    const bonePos = (name: string) =>
        root
            .getObjectByName(boneName(name))
            ?.getWorldPosition(new THREE.Vector3()) ?? null;
    const put = (bone: string, object: THREE.Object3D) => {
        const target = root.getObjectByName(boneName(bone));
        if (!target) {
            return;
        }
        scene.add(object);
        target.attach(object);
        made.push(object);
    };
    const bird = has('Foot.L');
    const topNeck = has('Neck3') ? 'Neck3' : 'Neck2';
    const back = has('Torso3') ? 'Torso3' : 'Body';
    const middle = has('Torso2') ? 'Torso2' : 'Body';

    for (const item of wearing) {
        if (item.spot === 'hat') {
            const surface = samples(meshes, ['Head']);
            if (surface.length === 0) {
                continue;
            }
            const headMid = centroid(surface.map((v) => v.p));
            const neckMid = centroid(
                points(meshes, [has('Neck3') ? 'Neck3' : 'Neck1']),
            );
            const forward = headMid.clone().sub(neckMid).setY(0).normalize();
            const side = new THREE.Vector3()
                .crossVectors(UP, forward)
                .normalize();
            const across = surface.map((v) => v.p.dot(side));
            const width = Math.max(...across) - Math.min(...across);
            // Never smaller than a tenth of the animal, so a slim head still gets a hat you can see.
            const r = Math.max(width * HAT_SIZE, size * 0.055);
            const earL = points(meshes, ['Ear1.L']);
            const earR = points(meshes, ['Ear1.R']);
            let spot: THREE.Vector3;
            if (earL.length && earR.length) {
                // Just in front of the ears: close-set ears come up through the back of the brim.
                spot = centroid(earL)
                    .lerp(centroid(earR), 0.5)
                    .addScaledVector(
                        forward,
                        Math.min(
                            centroid(earL).distanceTo(centroid(earR)) * 0.45,
                            r * 0.35,
                        ),
                    );
            } else {
                spot = surface
                    .reduce((a, v) => (v.p.y > a.p.y ? v : a), surface[0])
                    .p.clone();
            }
            // Ears set behind or below the skull (the sheep's) leave that spot off the head: use the crown.
            if (
                surface.filter(
                    (v) =>
                        Math.hypot(v.p.x - spot.x, v.p.z - spot.z) <
                        width * 0.35,
                ).length < 4
            ) {
                const top = Math.max(...surface.map((v) => v.p.y));
                spot = centroid(
                    surface
                        .filter((v) => v.p.y > top - width * 0.25)
                        .map((v) => v.p),
                );
            }
            const near = surface.filter(
                (v) => Math.hypot(v.p.x - spot.x, v.p.z - spot.z) < r * 0.6,
            );
            const topY = near.reduce((m, v) => Math.max(m, v.p.y), spot.y);
            const facing = near
                .filter((v) => v.p.y > topY - r * 0.5 && v.n.y > 0.2)
                .reduce((a, v) => a.add(v.n), new THREE.Vector3());
            // Mostly level, leaning only a little with the slope of the head.
            const normal =
                facing.lengthSq() > 0
                    ? facing.normalize().lerp(UP, 0.85).normalize()
                    : UP.clone();
            const hat = new THREE.Group();
            const brim = new THREE.Mesh(
                new THREE.CylinderGeometry(r, r, r * 0.08, 24),
                fabric(item, [6, 1]),
            );
            const crown = new THREE.Mesh(
                new THREE.CylinderGeometry(r * 0.5, r * 0.56, r * 0.6, 24),
                fabric(item, [4, 1]),
            );
            const band = new THREE.Mesh(
                new THREE.CylinderGeometry(r * 0.565, r * 0.565, r * 0.14, 24),
                solid(trimOf(item)),
            );
            crown.position.y = r * 0.34;
            band.position.y = r * 0.11;
            hat.add(brim, crown, band);
            hat.quaternion.setFromUnitVectors(UP, normal);
            hat.position
                .set(spot.x, topY, spot.z)
                .addScaledVector(normal, -r * 0.05);
            put('Head', hat);
            continue;
        }

        const n1 = bonePos('Neck1');
        const n3 = bonePos(topNeck);
        const head = bonePos('Head');
        const neckBox = boxOf(points(meshes, ['Neck2', topNeck]));
        const neckR = neckBox.isEmpty()
            ? size * 0.04
            : Math.min(
                  neckBox.max.x - neckBox.min.x,
                  neckBox.max.y - neckBox.min.y,
              ) * 0.5;
        const neckRing = (at: number) => {
            if (!n1 || !n3) {
                return null;
            }
            // The neck's own cross-section: its centre and how far out it reaches.
            const axis = n3.clone().sub(n1).normalize();
            const mid = n1.clone().lerp(n3, at);
            const pts = points(meshes, ['Neck1', 'Neck2', 'Neck3']).filter(
                (p) => Math.abs(p.clone().sub(mid).dot(axis)) < neckR * 0.35,
            );
            const centre = pts.length ? centroid(pts) : mid;
            const flat = (p: THREE.Vector3) => {
                const d = p.clone().sub(centre);
                return d.sub(axis.clone().multiplyScalar(d.dot(axis)));
            };
            const reach = pts.reduce(
                (m, p) => Math.max(m, flat(p).length()),
                neckR,
            );
            return { centre, axis, reach };
        };

        if (item.spot === 'scarf') {
            const ring = neckRing(0.45);
            if (!ring) {
                continue;
            }
            const scarf = new THREE.Mesh(
                new THREE.TorusGeometry(ring.reach, ring.reach * 0.22, 8, 24),
                fabric(item, [8, 1]),
            );
            scarf.position.copy(ring.centre);
            scarf.lookAt(ring.centre.clone().add(ring.axis));
            put('Neck2', scarf);
        } else if (item.spot === 'bell') {
            const ring = neckRing(0.85);
            if (!ring || !head) {
                continue;
            }
            const collar = new THREE.Group();
            const strap = new THREE.Mesh(
                new THREE.TorusGeometry(
                    ring.reach * 0.98,
                    ring.reach * 0.09,
                    6,
                    24,
                ),
                solid(item.colours[0] ?? '#6b4a2b'),
            );
            strap.position.copy(ring.centre);
            strap.lookAt(ring.centre.clone().add(ring.axis));
            const cup = new THREE.Mesh(
                new THREE.SphereGeometry(
                    neckR * 0.38,
                    12,
                    8,
                    0,
                    Math.PI * 2,
                    0,
                    Math.PI * 0.6,
                ),
                solid(trimOf(item), { metalness: 0.5, roughness: 0.4 }),
            );
            cup.rotation.x = Math.PI;
            cup.position.set(
                ring.centre.x,
                ring.centre.y - ring.reach * 1.05,
                ring.centre.z,
            );
            collar.add(strap, cup);
            put(topNeck, collar);
        } else if (item.spot === 'cloak') {
            const body = boxOf(
                points(meshes, ['Torso', 'Torso2', 'Torso3', 'Back', 'Body']),
            );
            if (body.isEmpty()) {
                continue;
            }
            const c = body.getCenter(new THREE.Vector3());
            const w = body.max.x - body.min.x;
            const len = body.max.z - body.min.z;
            const h = body.max.y - body.min.y;
            // An open shell over the back and down both sides, as wide and deep as the body.
            const geo = new THREE.CylinderGeometry(
                w * 0.56,
                w * 0.56,
                len * 0.5,
                20,
                1,
                true,
                Math.PI * 0.42,
                Math.PI * 1.16,
            );
            geo.scale(1, 1, (h / w) * 0.98);
            const cloak = new THREE.Mesh(geo, fabric(item, [4, 2], true));
            cloak.rotation.x = Math.PI / 2;
            cloak.position.copy(c);
            put(middle, cloak);
        } else if (item.spot === 'ribbon') {
            // A rosette on the left shoulder, facing out.
            const shoulder = points(
                meshes,
                bird ? ['Body'] : ['FrontShoulder.L', 'FrontUpperLeg.L'],
            );
            if (shoulder.length === 0) {
                continue;
            }
            const outer = shoulder.reduce(
                (a, p) => (p.x > a.x ? p : a),
                shoulder[0],
            );
            const top = Math.max(...shoulder.map((p) => p.y));
            const at = new THREE.Vector3(
                outer.x,
                outer.y + (top - outer.y) * 0.4,
                outer.z,
            );
            const r = size * 0.035;
            const rosette = new THREE.Group();
            const disc = new THREE.Mesh(
                new THREE.CylinderGeometry(r, r, r * 0.25, 12),
                fabric(item, [3, 1]),
            );
            const heart = new THREE.Mesh(
                new THREE.CylinderGeometry(r * 0.45, r * 0.45, r * 0.35, 10),
                solid(trimOf(item)),
            );
            for (const tilt of [-0.3, 0.3]) {
                const tail = new THREE.Mesh(
                    new THREE.BoxGeometry(r * 0.25, r * 0.5, r * 1.3),
                    solid(trimOf(item)),
                );
                tail.position.set(0, -r * 0.1, -r * 0.9);
                tail.rotation.y = tilt;
                rosette.add(tail);
            }
            rosette.add(disc, heart);
            rosette.rotation.z = -Math.PI / 2;
            rosette.position.copy(at).add(new THREE.Vector3(r * 0.1, 0, 0));
            put(bird ? 'Body' : 'FrontShoulder.L', rosette);
        } else if (item.spot === 'gloves' || item.spot === 'shoes') {
            // Shoes go on every foot, gloves on the front ones; birds wear both on their two feet.
            const feet: [string, string[]][] = bird
                ? [
                      ['Foot.L', ['Foot.L']],
                      ['Foot.R', ['Foot.R']],
                  ]
                : item.spot === 'shoes'
                  ? [
                        ['IKFrontLeg.L', ['IKFrontLeg.L', 'FF.L']],
                        ['IKFrontLeg.R', ['IKFrontLeg.R', 'FF.R']],
                        ['IKBackLeg.L', ['IKBackLeg.L', 'FFB.L']],
                        ['IKBackLeg.R', ['IKBackLeg.R', 'FFB.R']],
                    ]
                  : [
                        ['IKFrontLeg.L', ['IKFrontLeg.L', 'FF.L']],
                        ['IKFrontLeg.R', ['IKFrontLeg.R', 'FF.R']],
                    ];
            for (const [bone, parts] of feet) {
                const b = boxOf(points(meshes, parts));
                if (b.isEmpty()) {
                    continue;
                }
                const c = b.getCenter(new THREE.Vector3());
                const r = Math.max(b.max.x - b.min.x, b.max.z - b.min.z) * 0.55;
                const piece =
                    item.spot === 'shoes'
                        ? new THREE.Mesh(
                              new THREE.CylinderGeometry(
                                  r * 1.02,
                                  r * 1.08,
                                  r * 0.45,
                                  12,
                              ),
                              solid(item.colours[0] ?? METAL, {
                                  metalness: 0.35,
                                  roughness: 0.5,
                              }),
                          )
                        : new THREE.Mesh(
                              new THREE.CylinderGeometry(
                                  r * 1.12,
                                  r * 1.08,
                                  r * 0.7,
                                  10,
                              ),
                              fabric(item, [4, 1]),
                          );
                piece.position.set(
                    c.x,
                    item.spot === 'shoes'
                        ? b.min.y + r * 0.2
                        : b.max.y - r * 0.1,
                    c.z,
                );
                put(bone, piece);
            }
        } else if (item.spot === 'carried') {
            const top = boxOf(
                points(meshes, has('Torso3') ? ['Torso3', 'Back'] : ['Body']),
            );
            if (top.isEmpty()) {
                continue;
            }
            const c = top.getCenter(new THREE.Vector3());
            const h = size * 0.32;
            const thing = new THREE.Group();
            if (item.template === 'banner') {
                const pole = new THREE.Mesh(
                    new THREE.CylinderGeometry(h * 0.015, h * 0.015, h, 6),
                    solid(WOOD),
                );
                pole.position.y = h / 2;
                const flag = new THREE.Mesh(
                    new THREE.PlaneGeometry(h * 0.42, h * 0.28),
                    fabric(item, [2, 1], true),
                );
                flag.position.set(0, h * 0.82, -h * 0.21);
                flag.rotation.y = Math.PI / 2;
                const edge = new THREE.Mesh(
                    new THREE.BoxGeometry(h * 0.012, h * 0.03, h * 0.42),
                    solid(trimOf(item)),
                );
                edge.position.set(0, h * 0.67, -h * 0.21);
                thing.add(pole, flag, edge);
            } else if (item.template === 'pickaxe') {
                const handle = new THREE.Mesh(
                    new THREE.CylinderGeometry(h * 0.02, h * 0.02, h * 0.7, 6),
                    solid(WOOD),
                );
                handle.rotation.z = Math.PI / 2.4;
                handle.position.y = h * 0.12;
                const headPiece = new THREE.Mesh(
                    new THREE.BoxGeometry(h * 0.06, h * 0.06, h * 0.42),
                    solid(item.colours[0] ?? METAL, {
                        metalness: 0.4,
                        roughness: 0.5,
                    }),
                );
                headPiece.position.set(h * 0.27, h * 0.25, 0);
                thing.add(handle, headPiece);
            } else {
                // A basket.
                const basket = new THREE.Mesh(
                    new THREE.CylinderGeometry(
                        h * 0.16,
                        h * 0.12,
                        h * 0.16,
                        10,
                    ),
                    fabric(item, [4, 1]),
                );
                basket.position.y = h * 0.08;
                const handle = new THREE.Mesh(
                    new THREE.TorusGeometry(
                        h * 0.12,
                        h * 0.012,
                        6,
                        16,
                        Math.PI,
                    ),
                    solid(trimOf(item)),
                );
                handle.position.y = h * 0.16;
                thing.add(basket, handle);
            }
            thing.position.set(c.x, top.max.y - size * 0.01, c.z);
            put(back, thing);
        }
    }

    return made;
}
