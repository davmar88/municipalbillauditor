import { LocateFixed, Minus, Plus, Scan } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from 'three';
import { CSS2DRenderer } from 'three/examples/jsm/renderers/CSS2DRenderer.js';
import type { Size, WorldMapProps } from '@/components/game/world-map';
import {
    Compass,
    MapButton,
    openArea,
    shapeFor,
    walkPoint,
} from '@/components/game/world-map';
import type { Point } from '@/lib/hollowmere';
import { FARM, LANDS, hub, inside, reachable } from '@/lib/hollowmere';
import { cn } from '@/lib/utils';
import { AnimalMenu } from '@/components/game/animal-menu';
import { FolkCard } from '@/components/game/irongate-folk';
import type { Folk, MapAnimal, MapHome, MapRegion, Worn } from '@/types/game';
import { Rig } from './rig';
import { World } from './world';

type Props = WorldMapProps & {
    /** What your animal wears. */
    wearing: Worn[];
    /** The 3D map couldn't start (no WebGL, or the graphics card gave up). */
    onFail?: () => void;
};

type Gesture = {
    kind: 'pan' | 'pinch';
    /** The ground point that stays under the finger (or the pinch's middle). */
    anchor: Point | null;
    startK: number;
    origin: Point;
    spread: number;
    moved: number;
    region: number | null;
    /** The animal pressed on, if any: a tap or hold on it opens its menu. */
    animal: MapAnimal | null;
    /** One of Irongate's people pressed on, if any: a tap opens their card. */
    folk: Folk | null;
    velocity: Point;
    last: { point: Point; at: number };
    held?: boolean;
};

const LONG_PRESS_MS = 500;

/** No townsfolk, as one shared value so the scene isn't rebuilt for nothing. */
const NO_FOLK: Folk[] = [];
const NO_HOMES: MapHome[] = [];

const distance = (a: Point, b: Point) => Math.hypot(a[0] - b[0], a[1] - b[1]);
const middle = (a: Point, b: Point): Point => [
    (a[0] + b[0]) / 2,
    (a[1] + b[1]) / 2,
];

/** The region at a map point: the one drawn last wins, as on the 2D map (cleared fields sit over wild land). */
function regionAt(regions: MapRegion[], point: Point | null): number | null {
    if (!point) {
        return null;
    }
    for (let i = regions.length - 1; i >= 0; i--) {
        if (inside(point, shapeFor(regions[i]).polygon)) {
            return regions[i].id;
        }
    }
    return null;
}

/**
 * Hollowmere in 3D: the same map as the flat one, the land seen at a slant,
 * every animal standing on it as itself. It takes the same props and works
 * the same way: a quick tap walks there, a long press or right click shows
 * what's there, drag, pinch, scroll and keys move about.
 */
export default function WorldMap3D({
    valley,
    regions,
    here,
    house,
    species,
    selectedId,
    onSelect,
    gateNight,
    me,
    onWalk,
    gateResult,
    wanted,
    wearing,
    switcher,
    onFail,
    irongate,
    warrants,
    homes,
    children,
}: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const surfaceRef = useRef<HTMLDivElement>(null);
    const rigRef = useRef<Rig | null>(null);
    const worldRef = useRef<World | null>(null);
    const dirty = useRef(true);
    const [size, setSize] = useState<Size | null>(null);
    const [k, setK] = useState(0);
    const [dragging, setDragging] = useState(false);
    const [overLand, setOverLand] = useState(false);
    const [lost, setLost] = useState(false);
    // A tap, hold or right click on another animal opens its menu; on one of Irongate's people, their card.
    const [menu, setMenu] = useState<{ animal: MapAnimal; at: Point } | null>(
        null,
    );
    const [person, setPerson] = useState<{ folk: Folk; at: Point } | null>(
        null,
    );
    const lostRef = useRef(false);

    const byId = useMemo(
        () => new Map(regions.map((region) => [region.id, region])),
        [regions],
    );
    const hereRegion = here !== null ? (byId.get(here) ?? null) : null;
    const selected =
        selectedId !== null ? (byId.get(selectedId) ?? null) : null;
    // Where you are now: on a walk this changes every frame, so read it when needed.
    const herePoint = useCallback(
        (): Point | null =>
            me
                ? walkPoint(me, Date.now())
                : hereRegion
                  ? hub(hereRegion.key)
                  : null,
        [me, hereRegion],
    );

    const latest = useRef({ regions, onSelect, onWalk, herePoint });
    useEffect(() => {
        latest.current = { regions, onSelect, onWalk, herePoint };
    });

    // ---- the scene, made once ---------------------------------------------------------------------------

    useEffect(() => {
        const container = containerRef.current;
        const surface = surfaceRef.current;
        if (!container || !surface) {
            return;
        }
        const coarse =
            window.matchMedia?.('(pointer: coarse)').matches ?? false;
        let renderer: THREE.WebGLRenderer;
        try {
            renderer = new THREE.WebGLRenderer({
                antialias: !coarse,
                powerPreference: 'high-performance',
            });
        } catch {
            onFail?.();
            return;
        }
        renderer.setPixelRatio(
            Math.min(window.devicePixelRatio || 1, coarse ? 1.5 : 2),
        );
        renderer.domElement.className = 'absolute inset-0 size-full';
        surface.prepend(renderer.domElement);
        const labels = new CSS2DRenderer();
        Object.assign(labels.domElement.style, {
            position: 'absolute',
            inset: '0',
            pointerEvents: 'none',
            overflow: 'hidden',
            zIndex: '0',
        });
        surface.append(labels.domElement);

        const rig = new Rig();
        const world = new World(coarse);
        rigRef.current = rig;
        worldRef.current = world;

        const onLost = (event: Event) => {
            event.preventDefault();
            lostRef.current = true;
            setLost(true);
        };
        // When the browser gives the graphics back, three.js draws again.
        const onRestored = () => {
            lostRef.current = false;
            setLost(false);
            dirty.current = true;
        };
        renderer.domElement.addEventListener('webglcontextlost', onLost);
        renderer.domElement.addEventListener(
            'webglcontextrestored',
            onRestored,
        );

        let first = true;
        const observer = new ResizeObserver(([entry]) => {
            const { width, height } = entry.contentRect;
            if (width < 1 || height < 1) {
                return;
            }
            const next = { width, height };
            renderer.setSize(width, height, false);
            labels.setSize(width, height);
            world.resize(next);
            rig.resize(next);
            setSize(next);
            if (first) {
                first = false;
                // Phones start close enough to read, on your animal below the corner card;
                // wide screens see the whole farm.
                const { fit } = rig.limits();
                const zoom =
                    width < 720
                        ? Math.max(fit, Math.min(0.72, width / 640))
                        : fit;
                const focus = latest.current.herePoint();
                if (zoom > fit * 1.05 && focus) {
                    rig.lookAt(focus, zoom, [width / 2, height * 0.58], 0);
                } else {
                    const [centre, k] = rig.framing(FARM);
                    rig.go(centre, k, 0);
                }
            }
            dirty.current = true;
        });
        observer.observe(container);

        // Draw at full speed while things move; calmer while only animals breathe.
        let frame = 0;
        let lastDraw = performance.now();
        let lastLabels = 0;
        let lastK = 0;
        let onScreen = true;
        const seen = new IntersectionObserver(([entry]) => {
            onScreen = entry.isIntersecting;
        });
        seen.observe(container);
        const loop = (now: number) => {
            frame = requestAnimationFrame(loop);
            if (
                !onScreen ||
                document.hidden ||
                lostRef.current ||
                rig.size.width < 2
            ) {
                lastDraw = now;
                return;
            }
            const flying = rig.tick(now);
            const urgent = flying || dirty.current || world.urgent;
            if (!urgent && now - lastDraw < 32) {
                return;
            }
            const dt = Math.min(100, now - lastDraw);
            lastDraw = now;
            const labelsMoved = world.tick(
                Date.now(),
                dt,
                rig.k,
                rig.distance(),
                rig.target,
            );
            renderer.render(world.scene, rig.camera);
            // Labels follow the camera every frame; a walk alone moves them at most 30 times a second.
            if (
                flying ||
                dirty.current ||
                (labelsMoved && now - lastLabels >= 32)
            ) {
                lastLabels = now;
                world.fadeLabels((point, height) => rig.project(point, height));
                labels.render(world.scene, rig.camera);
                dirty.current = false;
            }
            if (Math.abs(rig.k - lastK) / Math.max(lastK, 1e-6) > 0.01) {
                lastK = rig.k;
                setK(rig.k);
            }
        };
        frame = requestAnimationFrame(loop);

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            seen.disconnect();
            renderer.domElement.removeEventListener('webglcontextlost', onLost);
            renderer.domElement.removeEventListener(
                'webglcontextrestored',
                onRestored,
            );
            world.dispose();
            renderer.dispose();
            // Hand the graphics context back now rather than whenever the canvas is collected.
            renderer.forceContextLoss();
            renderer.domElement.remove();
            labels.domElement.remove();
            rigRef.current = null;
            worldRef.current = null;
        };
        // The scene is made once; data comes in below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ---- data in --------------------------------------------------------------------------------------------

    const wantedKey = wanted.join(',');
    const wearingKey = JSON.stringify(wearing);
    useEffect(() => {
        worldRef.current?.update({
            regions,
            house,
            gateNight,
            gateResult,
            wanted,
            selectedId,
            me,
            species,
            wearing,
            folk: irongate?.folk ?? NO_FOLK,
            homes: homes ?? NO_HOMES,
        });
        dirty.current = true;
        // Only rebuild when what's shown changes, not on every render of the page.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        regions,
        house,
        gateNight,
        gateResult,
        wantedKey,
        selectedId,
        me,
        species,
        wearingKey,
        irongate,
        homes,
    ]);

    // ---- moving about ---------------------------------------------------------------------------------------------

    const pointers = useRef(new Map<number, Point>());
    const gesture = useRef<Gesture | null>(null);
    const lastTap = useRef<{ at: number; point: Point } | null>(null);
    const press = useRef<number | null>(null);
    const hover = useRef<number | null>(null);

    const cancelPress = () => {
        if (press.current !== null) {
            window.clearTimeout(press.current);
            press.current = null;
        }
    };
    useEffect(() => cancelPress, []);

    const local = (event: { clientX: number; clientY: number }): Point => {
        const rect = surfaceRef.current!.getBoundingClientRect();
        return [event.clientX - rect.left, event.clientY - rect.top];
    };

    const moved = () => {
        dirty.current = true;
    };

    // A card opened by a tap points at that spot; once the camera moves, it would point at the wrong thing.
    const closeCards = () => {
        setMenu(null);
        setPerson(null);
    };

    const zoomAt = useCallback(
        (point: Point, factor: number, animate = false) => {
            closeCards();
            rigRef.current?.zoomAt(point, factor, animate);
            moved();
        },
        [],
    );

    const lookAt = useCallback((point: Point, zoom?: number, spot?: Point) => {
        closeCards();
        rigRef.current?.lookAt(point, zoom, spot);
        moved();
    }, []);

    const fit = useCallback(() => {
        closeCards();
        const rig = rigRef.current;
        if (rig) {
            const [centre, k] = rig.framing(LANDS);
            rig.go(centre, k, 480);
            moved();
        }
    }, []);

    // The wheel zooms about the cursor; the page doesn't scroll.
    useEffect(() => {
        const element = surfaceRef.current;
        if (!element) {
            return;
        }
        const onWheel = (event: WheelEvent) => {
            event.preventDefault();
            const rect = element.getBoundingClientRect();
            const unit =
                event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? 400 : 1;
            const factor = Math.exp(
                -event.deltaY * unit * (event.ctrlKey ? 0.01 : 0.0016),
            );
            zoomAt(
                [event.clientX - rect.left, event.clientY - rect.top],
                factor,
            );
        };
        element.addEventListener('wheel', onWheel, { passive: false });
        return () => element.removeEventListener('wheel', onWheel);
    }, [zoomAt]);

    const setHover = (id: number | null, focus = false) => {
        if (hover.current !== id || focus) {
            hover.current = id;
            worldRef.current?.setHover(id, focus);
            moved();
        }
    };

    const onPointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
        const rig = rigRef.current;
        if (!rig) {
            return;
        }
        const point = local(event);
        setMenu(null);
        setPerson(null);
        const project = (p: Point, height: number) => rig.project(p, height);
        const animal = worldRef.current?.animalAt(point, project) ?? null;
        const folk = animal
            ? null
            : (worldRef.current?.folkAt(point, project) ?? null);
        // A right click asks what is there: an animal's menu, a person's card, or the region's card.
        if (event.pointerType === 'mouse' && event.button === 2) {
            if (animal) {
                setMenu({ animal, at: point });
            } else if (folk) {
                setPerson({ folk, at: point });
            } else {
                latest.current.onSelect(
                    regionAt(latest.current.regions, rig.pick(point)),
                );
            }
            return;
        }
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }
        rig.stop();
        const now = performance.now();
        pointers.current.set(event.pointerId, point);
        event.currentTarget.setPointerCapture(event.pointerId);

        if (pointers.current.size === 1) {
            const anchor = rig.pick(point);
            const region = regionAt(latest.current.regions, anchor);
            gesture.current = {
                kind: 'pan',
                anchor,
                startK: rig.k,
                origin: point,
                spread: 0,
                moved: 0,
                region,
                animal,
                folk,
                velocity: [0, 0],
                last: { point, at: now },
            };
            // Held still long enough, a press asks what is there.
            cancelPress();
            press.current = window.setTimeout(() => {
                press.current = null;
                const held = gesture.current;
                if (held?.kind === 'pan' && held.moved < 5) {
                    held.held = true;
                    if (animal) {
                        setMenu({ animal, at: point });
                    } else if (folk) {
                        setPerson({ folk, at: point });
                    } else {
                        latest.current.onSelect(region);
                    }
                }
            }, LONG_PRESS_MS);
        } else if (pointers.current.size === 2) {
            cancelPress();
            const [a, b] = [...pointers.current.values()];
            const centre = middle(a, b);
            gesture.current = {
                kind: 'pinch',
                anchor: rig.pick(centre),
                startK: rig.k,
                origin: centre,
                spread: Math.max(1, distance(a, b)),
                moved: Infinity,
                region: null,
                animal: null,
                folk: null,
                velocity: [0, 0],
                last: { point: centre, at: now },
            };
        }
    };

    const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
        const rig = rigRef.current;
        if (!rig) {
            return;
        }
        const point = local(event);
        if (!pointers.current.has(event.pointerId)) {
            // A mouse just passing over lights up the region under it.
            if (event.pointerType === 'mouse') {
                const id = regionAt(latest.current.regions, rig.pick(point));
                setHover(id);
                const project = (p: Point, height: number) =>
                    rig.project(p, height);
                const ground = rig.pick(point);
                setOverLand(
                    id !== null ||
                        (ground !== null && reachable(ground)) ||
                        worldRef.current?.animalAt(point, project) != null ||
                        worldRef.current?.folkAt(point, project) != null,
                );
            }
            return;
        }
        pointers.current.set(event.pointerId, point);
        const current = gesture.current;
        if (!current) {
            return;
        }
        if (current.kind === 'pan') {
            current.moved = Math.max(
                current.moved,
                distance(point, current.origin),
            );
            if (current.moved < 5) {
                return;
            }
            cancelPress();
            setDragging(true);
            const now = performance.now();
            const dt = Math.max(1, now - current.last.at);
            current.velocity = [
                ((point[0] - current.last.point[0]) / dt) * 0.8 +
                    current.velocity[0] * 0.2,
                ((point[1] - current.last.point[1]) / dt) * 0.8 +
                    current.velocity[1] * 0.2,
            ];
            current.last = { point, at: now };
            // The ground grabbed stays under the finger.
            if (current.anchor) {
                rig.keepUnder(current.anchor, point);
            } else {
                current.anchor = rig.pick(point);
            }
            moved();
            return;
        }
        if (pointers.current.size >= 2) {
            const [a, b] = [...pointers.current.values()];
            const centre = middle(a, b);
            rig.set(
                rig.target,
                (current.startK * distance(a, b)) / current.spread,
            );
            if (current.anchor) {
                rig.keepUnder(current.anchor, centre);
            }
            moved();
        }
    };

    const onPointerUp = (event: React.PointerEvent<HTMLDivElement>) => {
        const rig = rigRef.current;
        if (!rig || !pointers.current.has(event.pointerId)) {
            return;
        }
        cancelPress();
        pointers.current.delete(event.pointerId);
        const current = gesture.current;

        if (current?.kind === 'pinch' && pointers.current.size === 1) {
            // Lifting one finger of a pinch carries on as a drag.
            const [point] = [...pointers.current.values()];
            gesture.current = {
                ...current,
                kind: 'pan',
                anchor: rig.pick(point),
                origin: point,
                last: { point, at: performance.now() },
            };
            return;
        }
        if (pointers.current.size > 0) {
            return;
        }
        gesture.current = null;
        setDragging(false);
        if (
            !current ||
            event.type !== 'pointerup' ||
            (current.kind === 'pan' && current.held)
        ) {
            return;
        }
        if (current.kind === 'pan' && current.moved < 5) {
            const point = local(event);
            const now = performance.now();
            if (
                lastTap.current &&
                now - lastTap.current.at < 320 &&
                distance(point, lastTap.current.point) < 28
            ) {
                lastTap.current = null;
                zoomAt(point, 1.8, true);
                return;
            }
            lastTap.current = { at: now, point };
            // A tap on an animal opens its menu instead of walking.
            if (current.animal) {
                setMenu({ animal: current.animal, at: point });
                return;
            }
            if (current.folk) {
                setPerson({ folk: current.folk, at: point });
                return;
            }
            // A quick tap on land sets off straight for that spot, the open scrub between the regions included.
            const ground = rig.pick(point);
            if (
                ground &&
                ((current.region !== null && current.region > 0) ||
                    reachable(ground))
            ) {
                latest.current.onWalk?.(ground);
            }
            return;
        }
        if (
            current.kind === 'pan' &&
            performance.now() - current.last.at < 90 &&
            Math.hypot(...current.velocity) > 0.25
        ) {
            // A flick carries on sliding: the ground under the finger keeps the speed it had.
            const [vx, vy] = current.velocity;
            const p = current.last.point;
            const a = rig.pick(p);
            const b = rig.pick([p[0] - vx * 16, p[1] - vy * 16]);
            if (a && b) {
                rig.glide([(b[0] - a[0]) / 16, (b[1] - a[1]) / 16]);
            }
        }
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
        const rig = rigRef.current;
        if (!rig || !size || event.target !== event.currentTarget) {
            return;
        }
        closeCards();
        const centre: Point = [size.width / 2, size.height / 2];
        const moves: Record<string, Point> = {
            ArrowLeft: [-90, 0],
            ArrowRight: [90, 0],
            ArrowUp: [0, -90],
            ArrowDown: [0, 90],
        };
        const step = moves[event.key];
        if (step) {
            event.preventDefault();
            const from = rig.pick(centre);
            const to = rig.pick([centre[0] + step[0], centre[1] + step[1]]);
            if (from && to) {
                rig.go(
                    [
                        rig.target[0] + to[0] - from[0],
                        rig.target[1] + to[1] - from[1],
                    ],
                    rig.k,
                    200,
                );
                moved();
            }
        } else if (event.key === '+' || event.key === '=') {
            zoomAt(centre, 1.4, true);
        } else if (event.key === '-' || event.key === '_') {
            zoomAt(centre, 1 / 1.4, true);
        }
    };

    // ---- keeping what you picked in sight ------------------------------------------------------------------------

    const bringIntoView = useCallback(
        (point: Point | null, panelOpen: boolean) => {
            const rig = rigRef.current;
            if (!point || !rig || !size) {
                return;
            }
            const area = openArea(size, panelOpen);
            const [sx, sy] = rig.project(point);
            if (
                sx < area.left ||
                sx > area.right ||
                sy < area.top ||
                sy > area.bottom
            ) {
                lookAt(point, rig.k, [
                    (area.left + area.right) / 2,
                    (area.top + area.bottom) / 2,
                ]);
            }
        },
        [size, lookAt],
    );

    useEffect(() => {
        if (selected) {
            bringIntoView(shapeFor(selected).centre, true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedId]);

    useEffect(() => {
        bringIntoView(herePoint(), selected !== null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [here, me?.walk?.arrivesAt]);

    const ready = size !== null;
    const zoom = k || 1;
    // A scale bar of a round number of paces, measured at the middle of the screen.
    const paces = [25, 50, 100, 200, 400].find((p) => p * zoom >= 70) ?? 400;
    const located = me !== null || hereRegion !== null;

    return (
        <div
            ref={containerRef}
            className="dark relative h-[calc(100svh-15rem)] min-h-[400px] w-full overflow-hidden rounded-2xl border border-[#2e2e32] bg-[#13151a] text-foreground md:h-[calc(100svh-10.5rem)] md:min-h-[520px]"
        >
            <div
                ref={surfaceRef}
                className={cn(
                    'absolute inset-0 touch-none outline-none select-none [-webkit-touch-callout:none]',
                    dragging
                        ? 'cursor-grabbing'
                        : overLand
                          ? 'cursor-pointer'
                          : 'cursor-grab',
                )}
                role="group"
                aria-label={`Map of ${valley}. Drag to look around, scroll or pinch to zoom, tap a spot to walk there, and press and hold or right-click to see what's there.`}
                tabIndex={0}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                onPointerLeave={() => {
                    setHover(null);
                    setOverLand(false);
                }}
                onKeyDown={onKeyDown}
                onContextMenu={(event) => event.preventDefault()}
            />

            {/* Every region as a button, for the keyboard and screen readers. */}
            <div className="sr-only">
                {regions.map((region) => (
                    <button
                        key={region.id}
                        type="button"
                        aria-label={
                            region.faction
                                ? `${region.name}, held by ${region.faction.name}`
                                : region.name
                        }
                        aria-pressed={region.id === selectedId}
                        onClick={() => onSelect(region.id)}
                        onFocus={() => {
                            setHover(region.id, true);
                            bringIntoView(
                                shapeFor(region).centre,
                                selectedId !== null,
                            );
                        }}
                        onBlur={() => setHover(null)}
                    />
                ))}
            </div>

            {lost && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-[#13151a]/90 text-sm">
                    <p>
                        The 3D map stopped. Your phone or computer may be short
                        of graphics memory.
                    </p>
                    {onFail && (
                        <button
                            type="button"
                            onClick={onFail}
                            className="rounded-lg border px-3 py-1.5 font-semibold hover:bg-accent"
                        >
                            Show the flat map
                        </button>
                    )}
                </div>
            )}

            {ready && (
                <>
                    <div className="pointer-events-none absolute top-3 right-3 flex flex-col gap-2">
                        <div className="pointer-events-auto flex flex-col overflow-hidden rounded-xl border bg-card/90 shadow-lg backdrop-blur">
                            <MapButton
                                label="Zoom in"
                                onClick={() =>
                                    zoomAt(
                                        [size.width / 2, size.height / 2],
                                        1.5,
                                        true,
                                    )
                                }
                            >
                                <Plus className="size-4" />
                            </MapButton>
                            <MapButton
                                label="Zoom out"
                                onClick={() =>
                                    zoomAt(
                                        [size.width / 2, size.height / 2],
                                        1 / 1.5,
                                        true,
                                    )
                                }
                                divided
                            >
                                <Minus className="size-4" />
                            </MapButton>
                        </div>
                        <div className="pointer-events-auto flex flex-col overflow-hidden rounded-xl border bg-card/90 shadow-lg backdrop-blur">
                            {located && (
                                <MapButton
                                    label="Find my animal"
                                    onClick={() => {
                                        const point = herePoint();
                                        if (point) {
                                            lookAt(
                                                point,
                                                Math.max(
                                                    rigRef.current?.k ?? 0.95,
                                                    0.95,
                                                ),
                                            );
                                        }
                                    }}
                                >
                                    <LocateFixed className="size-4" />
                                </MapButton>
                            )}
                            <MapButton
                                label="See the whole valley"
                                onClick={fit}
                                divided={located}
                            >
                                <Scan className="size-4" />
                            </MapButton>
                        </div>
                        {switcher}
                    </div>

                    <div
                        className={cn(
                            'pointer-events-none absolute right-3 bottom-3 flex items-end gap-2',
                            selected && 'max-md:hidden',
                        )}
                    >
                        <div className="flex flex-col items-center gap-0.5 rounded-lg bg-card/80 px-2 py-1 text-[10px] font-semibold text-muted-foreground backdrop-blur">
                            <span>{paces} paces</span>
                            <span
                                className="h-1.5 border-x-2 border-b-2 border-muted-foreground"
                                style={{ width: paces * zoom }}
                            />
                        </div>
                        <Compass />
                    </div>
                </>
            )}

            {person && size && irongate && (
                <FolkCard
                    key={person.folk.key}
                    folk={person.folk}
                    at={person.at}
                    size={size}
                    irongate={irongate}
                    warrants={warrants ?? []}
                    regions={regions}
                    here={here}
                    onClose={() => setPerson(null)}
                />
            )}

            {menu && size && (
                <AnimalMenu
                    key={menu.animal.id}
                    animal={menu.animal}
                    at={menu.at}
                    size={size}
                    wanted={wanted.includes(menu.animal.id)}
                    onClose={() => setMenu(null)}
                />
            )}

            {children}
        </div>
    );
}
