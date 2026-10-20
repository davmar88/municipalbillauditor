import { useCallback, useState } from 'react';

export type MapView = '2d' | '3d';

const KEY = 'hollowmere-map-view';

/** A phone: it starts on the flat map, which is far lighter, and remembers its own choice. */
const phone = () =>
    typeof window !== 'undefined' &&
    (window.matchMedia?.('(pointer: coarse)').matches ?? false);

const key = () => (phone() ? `${KEY}:phone` : KEY);

let webgl: boolean | null = null;

/** Whether this browser can draw the 3D map at all: three.js needs WebGL 2. */
export function canDraw3d(): boolean {
    if (webgl === null) {
        try {
            const gl = document.createElement('canvas').getContext('webgl2');
            webgl = gl !== null;
            // Don't keep one of the browser's few graphics contexts for a test.
            gl?.getExtension('WEBGL_lose_context')?.loseContext();
        } catch {
            webgl = false;
        }
    }

    return webgl;
}

function stored(): MapView | null {
    try {
        const value = window.localStorage.getItem(key());

        return value === '2d' || value === '3d' ? value : null;
    } catch {
        return null;
    }
}

/**
 * The flat map or the 3D one, remembered on this device. 3D unless this
 * browser can't draw it, you switched back to the flat map, or it's a phone.
 */
export function useMapView() {
    const [view, setView] = useState<MapView>(() =>
        canDraw3d() ? (stored() ?? (phone() ? '2d' : '3d')) : '2d',
    );

    const choose = useCallback((next: MapView) => {
        setView(next);

        try {
            window.localStorage.setItem(key(), next);
        } catch {
            // Private windows may not keep it; the choice still holds for now.
        }
    }, []);

    return { view, choose, available: canDraw3d() };
}
