import * as THREE from 'three';

/** Coat and hat colours for each of Irongate's people. */
const DRESS: Record<string, { coat: string; hat: string | null }> = {
    brannock: { coat: '#8e3b2f', hat: '#8a8a94' },
    vetch: { coat: '#6b4f7a', hat: null },
    wick: { coat: '#2b2a33', hat: '#1f1d24' },
    tamsin: { coat: '#7a7480', hat: null },
    fallow: { coat: '#6b4a2e', hat: '#3d2c1e' },
};

const SKIN = new THREE.MeshLambertMaterial({
    color: '#e0b48a',
    flatShading: true,
});
const BOOTS = new THREE.MeshLambertMaterial({
    color: '#2a2320',
    flatShading: true,
});

/**
 * One of Irongate's people as a little figure: boots, a coat in their
 * colour, a head and, for some, a hat. One unit tall, standing at the
 * origin, facing south; scale it to the size it should show at.
 */
export function person(key: string): THREE.Group {
    const dress = DRESS[key] ?? { coat: '#5a5266', hat: null };
    const coat = new THREE.MeshLambertMaterial({
        color: dress.coat,
        flatShading: true,
    });
    const g = new THREE.Group();
    for (const x of [-0.07, 0.07]) {
        const leg = new THREE.Mesh(
            new THREE.BoxGeometry(0.09, 0.36, 0.1),
            BOOTS,
        );
        leg.position.set(x, 0.18, 0);
        g.add(leg);
    }
    const body = new THREE.Mesh(
        new THREE.CylinderGeometry(0.13, 0.17, 0.42, 8),
        coat,
    );
    body.position.y = 0.57;
    g.add(body);
    for (const x of [-0.2, 0.2]) {
        const arm = new THREE.Mesh(
            new THREE.BoxGeometry(0.07, 0.34, 0.08),
            coat,
        );
        arm.position.set(x, 0.58, 0);
        arm.rotation.z = x > 0 ? -0.12 : 0.12;
        g.add(arm);
    }
    const head = new THREE.Mesh(new THREE.SphereGeometry(0.11, 10, 8), SKIN);
    head.position.y = 0.9;
    g.add(head);
    if (dress.hat) {
        const hat = new THREE.Mesh(
            key === 'brannock'
                ? new THREE.SphereGeometry(
                      0.12,
                      10,
                      6,
                      0,
                      Math.PI * 2,
                      0,
                      Math.PI / 2,
                  )
                : new THREE.CylinderGeometry(0.09, 0.14, 0.1, 10),
            new THREE.MeshLambertMaterial({
                color: dress.hat,
                flatShading: true,
            }),
        );
        hat.position.y = key === 'brannock' ? 0.93 : 1.0;
        g.add(hat);
    }
    return g;
}
