import * as THREE from 'three';
import type { BannerData } from '@/types/game';

/**
 * A faction's banner as a picture: the same patterns as the banner on its
 * page, drawn as an SVG for the flat map and as a texture for the 3D map's
 * flags. An uploaded banner is used as it is.
 */

function patternSvg(pattern: string, charge: string): string {
    switch (pattern) {
        case 'stripes':
            return [10, 30, 50]
                .map(
                    (x) =>
                        `<rect x="${x}" y="0" width="10" height="80" fill="${charge}"/>`,
                )
                .join('');
        case 'chevron':
            return `<path d="M0 52L30 26L60 52V68L30 42L0 68z" fill="${charge}"/>`;
        case 'cross':
            return `<rect x="24" y="0" width="12" height="80" fill="${charge}"/><rect x="0" y="30" width="60" height="12" fill="${charge}"/>`;
        case 'saltire':
            return `<path d="M0 0h10L60 70v10H50L0 10zM60 0v10L10 80H0V70L50 0z" fill="${charge}"/>`;
        case 'quarters':
            return `<rect x="30" y="0" width="30" height="36" fill="${charge}"/><rect x="0" y="36" width="30" height="44" fill="${charge}"/>`;
        case 'bend':
            return `<path d="M0 0h16L60 60v20H44L0 20z" fill="${charge}"/>`;
        case 'border':
            return `<path d="M0 0h60v80H0zM7 7v66h46V7z" fill="${charge}" fill-rule="evenodd"/>`;
        case 'chief':
            return `<rect x="0" y="0" width="60" height="22" fill="${charge}"/>`;
        default:
            return '';
    }
}

const escape = (text: string): string =>
    text.replace(
        /[&<>"]/g,
        (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c,
    );

/** The banner as an image URL: the uploaded picture, or the pattern drawn as an SVG. */
export function bannerUrl(banner: BannerData): string {
    if (banner.mode === 'image' && banner.image) {
        return banner.image;
    }
    const [field, charge] = banner.colours;
    const svg =
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 80" width="120" height="160">` +
        `<clipPath id="c"><path d="M0 0h60v80L30 66L0 80z"/></clipPath>` +
        `<g clip-path="url(#c)"><rect width="60" height="80" fill="${escape(field)}"/>${patternSvg(banner.pattern, escape(charge))}` +
        (banner.emblem
            ? `<text x="30" y="40" text-anchor="middle" dominant-baseline="central" font-size="24">${escape(banner.emblem)}</text>`
            : '') +
        `</g><path d="M0 0h60v80L30 66L0 80z" fill="none" stroke="rgba(0,0,0,0.35)" stroke-width="1.5"/></svg>`;

    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

const textures = new Map<string, THREE.Texture>();

/** The banner as a texture for a flag, made once per look and shared. */
export function bannerTexture(
    banner: BannerData,
    onReady: () => void,
): THREE.Texture {
    const url = bannerUrl(banner);
    let texture = textures.get(url);
    if (!texture) {
        const canvas = document.createElement('canvas');
        canvas.width = 120;
        canvas.height = 160;
        texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        textures.set(url, texture);
        const made = texture;
        const image = new Image();
        image.onload = () => {
            const context = canvas.getContext('2d');
            if (context) {
                context.clearRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                made.needsUpdate = true;
                onReady();
            }
        };
        image.src = url;
    }
    return texture;
}

/** A flagpole flying a faction's banner, about `height` tall, standing at its origin. */
export function bannerFlag(
    banner: BannerData,
    height: number,
    onReady: () => void,
): THREE.Group {
    const flag = new THREE.Group();
    const pole = new THREE.Mesh(
        new THREE.CylinderGeometry(height * 0.012, height * 0.012, height, 6),
        new THREE.MeshLambertMaterial({ color: '#8b6b4a' }),
    );
    pole.position.y = height / 2;
    const bar = new THREE.Mesh(
        new THREE.BoxGeometry(height * 0.34, height * 0.015, height * 0.015),
        pole.material,
    );
    bar.position.set(height * 0.16, height * 0.98, 0);
    const cloth = new THREE.Mesh(
        new THREE.PlaneGeometry(height * 0.3, height * 0.4),
        new THREE.MeshLambertMaterial({
            map: bannerTexture(banner, onReady),
            transparent: true,
            side: THREE.DoubleSide,
        }),
    );
    cloth.position.set(height * 0.16, height * 0.78, 0);
    cloth.userData.banner = true;
    flag.add(pole, bar, cloth);

    return flag;
}
