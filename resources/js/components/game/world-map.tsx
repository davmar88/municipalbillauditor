import { LocateFixed, Minus, Plus, Scan } from 'lucide-react';
import type { ReactNode } from 'react';
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AnimalMenu } from '@/components/game/animal-menu';
import { FolkCard, folkFace } from '@/components/game/irongate-folk';
import {
    FieldGround,
    MapFeatures,
    MapGround,
} from '@/components/game/map-terrain';
import { bannerUrl } from '@/lib/banner';
import { clockTime, speciesEmoji } from '@/lib/game';
import type { Point } from '@/lib/hollowmere';
import {
    BROOK,
    FARM,
    FIELDS,
    LANDS,
    reachable,
    WORLD,
    anchor,
    hub,
    plot,
    shapeOf,
    smoothPath,
    toPath,
} from '@/lib/hollowmere';
import { cn } from '@/lib/utils';
import { cityLots } from '@/lib/city-lots';
import type { Warrant } from '@/components/game/region-panel';
import type {
    BannerData,
    BuildingKindValue,
    FactionRef,
    Folk as FolkPerson,
    MapHome,
    IrongateInfo,
    MapAnimal,
    MapRegion,
    Species,
    MapMe,
    LootPile,
    Strike,
    Walker,
} from '@/types/game';

type View = { x: number; y: number; k: number };
export type Size = { width: number; height: number };

// Twice as far in as before, so the land holds twice the room round animals and homes.
export const MAX_ZOOM = 6;
export const LABEL = '#efecf7';
export const HALO = '#0b0a0f';
export const VIOLET = '#b39aff';
export const FLAG = '#dd6b30';

/** Desktop puts the region card on the left; phones slide it up from the bottom. */
export const PANEL_WIDTH = 384;

export function shapeFor(region: MapRegion) {
    return shapeOf(region.key, region.col, region.row, region.ring === 'safe');
}

export function zoomLimits(size: Size) {
    const fit = Math.min(size.width / FARM.width, size.height / FARM.height);
    const all = Math.min(size.width / LANDS.width, size.height / LANDS.height);

    // You start on Hollowmere and can zoom out to see the farms round it.
    return { fit, all, min: all * 0.95, max: MAX_ZOOM };
}

function clampView(view: View, size: Size | null): View {
    if (!size) {
        return view;
    }

    const { min, max } = zoomLimits(size);
    const k = Math.min(max, Math.max(min, view.k));
    const width = WORLD.width * k;
    const height = WORLD.height * k;
    const left = -WORLD.x * k;
    const top = -WORLD.y * k;

    return {
        k,
        x:
            width <= size.width
                ? (size.width - width) / 2 + left
                : Math.min(left, Math.max(size.width - width + left, view.x)),
        y:
            height <= size.height
                ? (size.height - height) / 2 + top
                : Math.min(top, Math.max(size.height - height + top, view.y)),
    };
}

function firstView(size: Size, focus: Point | null): View {
    const { fit } = zoomLimits(size);
    // Phones start close enough to read, on the animal, below the corner card;
    // wide screens see the whole farm.
    const k =
        size.width < 720
            ? Math.max(fit, Math.min(0.72, size.width / 640))
            : fit;

    if (k > fit * 1.05 && focus) {
        return {
            k,
            x: size.width / 2 - focus[0] * k,
            y: size.height * 0.58 - focus[1] * k,
        };
    }

    return {
        k,
        x: size.width / 2 - (FARM.x + FARM.width / 2) * k,
        y: size.height / 2 - (FARM.y + FARM.height / 2) * k,
    };
}

/** Where on screen a region should sit so the region card doesn't cover it. */
export function openArea(size: Size, panelOpen: boolean) {
    if (!panelOpen) {
        return {
            left: 40,
            top: 40,
            right: size.width - 40,
            bottom: size.height - 40,
        };
    }

    if (size.width >= 768) {
        return {
            left: PANEL_WIDTH + 40,
            top: 40,
            right: size.width - 70,
            bottom: size.height - 40,
        };
    }

    return {
        left: 30,
        top: 70,
        right: size.width - 30,
        bottom: size.height * 0.42,
    };
}

function distance(a: Point, b: Point): number {
    return Math.hypot(a[0] - b[0], a[1] - b[1]);
}

function middle(a: Point, b: Point): Point {
    return [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
}

type Gesture = {
    kind: 'pan' | 'pinch';
    start: View;
    origin: Point;
    spread: number;
    moved: number;
    target: Element | null;
    /** The animal pressed on, if any: a tap or hold on it opens its menu. */
    animal: Element | null;
    velocity: Point;
    last: { point: Point; at: number };
    /** A long press already opened the card, so letting go doesn't walk. */
    held?: boolean;
};

/** How long a press is held before it opens the card instead of walking. */
const LONG_PRESS_MS = 500;

/**
 * Dragging, flicking, pinching and wheel zoom, like a street map. The view
 * maps the world onto the screen: screen = world × k + (x, y). A quick tap
 * or a left click walks; a long press or a right click asks what is there.
 */
function useCamera(
    container: React.RefObject<HTMLDivElement | null>,
    svg: React.RefObject<SVGSVGElement | null>,
    focus: Point | null,
    onTap: (target: Element | null, world: Point) => void,
    onInfo: (target: Element | null, world: Point) => void,
    onAnimal: (animal: Element, screen: Point) => void,
) {
    const [view, setView] = useState<View>({ x: 0, y: 0, k: 0 });
    const [size, setSize] = useState<Size | null>(null);
    const [dragging, setDragging] = useState(false);
    const viewRef = useRef(view);
    const sizeRef = useRef<Size | null>(null);
    const frame = useRef<number | null>(null);
    const pointers = useRef(new Map<number, Point>());
    const gesture = useRef<Gesture | null>(null);
    const lastTap = useRef<{ at: number; point: Point } | null>(null);
    const focusRef = useRef(focus);
    const tapRef = useRef(onTap);
    const infoRef = useRef(onInfo);
    const animalRef = useRef(onAnimal);
    const press = useRef<number | null>(null);

    useEffect(() => {
        focusRef.current = focus;
        tapRef.current = onTap;
        infoRef.current = onInfo;
        animalRef.current = onAnimal;
    });

    const cancelPress = () => {
        if (press.current !== null) {
            window.clearTimeout(press.current);
            press.current = null;
        }
    };

    const apply = useCallback((next: View) => {
        const clamped = clampView(next, sizeRef.current);
        viewRef.current = clamped;
        setView(clamped);
    }, []);

    const stop = useCallback(() => {
        if (frame.current !== null) {
            cancelAnimationFrame(frame.current);
            frame.current = null;
        }
    }, []);

    const fly = useCallback(
        (target: View, duration = 480) => {
            stop();
            const from = viewRef.current;
            const to = clampView(target, sizeRef.current);
            const started = performance.now();

            const step = (now: number) => {
                const t = Math.min(1, (now - started) / duration);
                const ease =
                    t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2;
                apply({
                    x: from.x + (to.x - from.x) * ease,
                    y: from.y + (to.y - from.y) * ease,
                    k: from.k + (to.k - from.k) * ease,
                });
                frame.current = t < 1 ? requestAnimationFrame(step) : null;
            };

            frame.current = requestAnimationFrame(step);
        },
        [apply, stop],
    );

    const glide = useCallback(
        (velocity: Point) => {
            stop();
            let last = performance.now();
            let [vx, vy] = velocity;

            const step = (now: number) => {
                const dt = Math.min(40, now - last);
                last = now;
                const current = viewRef.current;
                apply({
                    k: current.k,
                    x: current.x + vx * dt,
                    y: current.y + vy * dt,
                });
                const decay = 0.993 ** dt;
                vx *= decay;
                vy *= decay;
                frame.current =
                    Math.hypot(vx, vy) > 0.02
                        ? requestAnimationFrame(step)
                        : null;
            };

            frame.current = requestAnimationFrame(step);
        },
        [apply, stop],
    );

    const zoomAt = useCallback(
        (point: Point, factor: number, animate = false) => {
            const size = sizeRef.current;

            if (!size) {
                return;
            }

            const current = viewRef.current;
            const { min, max } = zoomLimits(size);
            const k = Math.min(max, Math.max(min, current.k * factor));
            const wx = (point[0] - current.x) / current.k;
            const wy = (point[1] - current.y) / current.k;
            const next = { k, x: point[0] - wx * k, y: point[1] - wy * k };

            if (animate) {
                fly(next, 280);
            } else {
                stop();
                apply(next);
            }
        },
        [apply, fly, stop],
    );

    /** Glide so a world point lands at a spot on screen (the middle by default). */
    const lookAt = useCallback(
        (point: Point, k?: number, spot?: Point) => {
            const size = sizeRef.current;

            if (!size) {
                return;
            }

            const zoom = k ?? viewRef.current.k;
            const [sx, sy] = spot ?? [size.width / 2, size.height / 2];
            fly({ k: zoom, x: sx - point[0] * zoom, y: sy - point[1] * zoom });
        },
        [fly],
    );

    const fit = useCallback(() => {
        const size = sizeRef.current;

        if (!size) {
            return;
        }

        const { all: k } = zoomLimits(size);
        lookAt([LANDS.x + LANDS.width / 2, LANDS.y + LANDS.height / 2], k);
    }, [lookAt]);

    useEffect(() => {
        const element = container.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            const { width, height } = entry.contentRect;
            const old = sizeRef.current;
            sizeRef.current = { width, height };
            setSize({ width, height });

            if (!old || viewRef.current.k === 0) {
                apply(firstView({ width, height }, focusRef.current));

                return;
            }

            // Keep the same spot of the farm in the middle.
            const current = viewRef.current;
            const cx = (old.width / 2 - current.x) / current.k;
            const cy = (old.height / 2 - current.y) / current.k;
            apply({
                k: current.k,
                x: width / 2 - cx * current.k,
                y: height / 2 - cy * current.k,
            });
        });

        observer.observe(element);

        return () => observer.disconnect();
    }, [apply, container]);

    useEffect(() => {
        const element = svg.current;

        if (!element) {
            return;
        }

        // A native listener, so the page doesn't scroll while the map zooms.
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
    }, [svg, zoomAt]);

    useEffect(() => stop, [stop]);

    const local = (event: { clientX: number; clientY: number }): Point => {
        const rect = svg.current!.getBoundingClientRect();

        return [event.clientX - rect.left, event.clientY - rect.top];
    };

    const onPointerDown = (event: React.PointerEvent<SVGSVGElement>) => {
        // Another animal or one of Irongate's people: a tap opens their card.
        const animal = (event.target as Element).closest(
            '[data-animal], [data-folk], [data-loot]',
        );

        // A right click asks what is there: an animal's menu, or the region's card.
        if (event.pointerType === 'mouse' && event.button === 2) {
            if (animal) {
                animalRef.current(animal, local(event));
            } else {
                const seen = viewRef.current;
                const at = local(event);
                infoRef.current(
                    (event.target as Element).closest('[data-region]'),
                    [(at[0] - seen.x) / seen.k, (at[1] - seen.y) / seen.k],
                );
            }

            return;
        }

        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        stop();
        const point = local(event);
        const now = performance.now();
        pointers.current.set(event.pointerId, point);
        event.currentTarget.setPointerCapture(event.pointerId);

        if (pointers.current.size === 1) {
            const target = (event.target as Element).closest('[data-region]');
            gesture.current = {
                kind: 'pan',
                start: viewRef.current,
                origin: point,
                spread: 0,
                moved: 0,
                target,
                animal,
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
                        animalRef.current(animal, point);
                    } else {
                        const seen = viewRef.current;
                        infoRef.current(target, [
                            (point[0] - seen.x) / seen.k,
                            (point[1] - seen.y) / seen.k,
                        ]);
                    }
                }
            }, LONG_PRESS_MS);
        } else if (pointers.current.size === 2) {
            cancelPress();
            const [a, b] = [...pointers.current.values()];
            gesture.current = {
                kind: 'pinch',
                start: viewRef.current,
                origin: middle(a, b),
                spread: Math.max(1, distance(a, b)),
                moved: Infinity,
                target: null,
                animal: null,
                velocity: [0, 0],
                last: { point: middle(a, b), at: now },
            };
        }
    };

    const onPointerMove = (event: React.PointerEvent<SVGSVGElement>) => {
        if (!pointers.current.has(event.pointerId)) {
            return;
        }

        const point = local(event);
        pointers.current.set(event.pointerId, point);
        const current = gesture.current;

        if (!current) {
            return;
        }

        if (current.kind === 'pan') {
            const dx = point[0] - current.origin[0];
            const dy = point[1] - current.origin[1];
            current.moved = Math.max(current.moved, Math.hypot(dx, dy));

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
            apply({
                k: current.start.k,
                x: current.start.x + dx,
                y: current.start.y + dy,
            });

            return;
        }

        if (pointers.current.size >= 2 && sizeRef.current) {
            const [a, b] = [...pointers.current.values()];
            const centre = middle(a, b);
            const { min, max } = zoomLimits(sizeRef.current);
            const k = Math.min(
                max,
                Math.max(
                    min,
                    (current.start.k * distance(a, b)) / current.spread,
                ),
            );
            const wx = (current.origin[0] - current.start.x) / current.start.k;
            const wy = (current.origin[1] - current.start.y) / current.start.k;
            apply({ k, x: centre[0] - wx * k, y: centre[1] - wy * k });
        }
    };

    const onPointerUp = (event: React.PointerEvent<SVGSVGElement>) => {
        if (!pointers.current.has(event.pointerId)) {
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
                start: viewRef.current,
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

        if (!current || event.type !== 'pointerup') {
            return;
        }

        if (current.kind === 'pan' && current.held) {
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
                animalRef.current(current.animal, point);

                return;
            }

            const seen = viewRef.current;
            tapRef.current(current.target, [
                (point[0] - seen.x) / seen.k,
                (point[1] - seen.y) / seen.k,
            ]);

            return;
        }

        if (
            current.kind === 'pan' &&
            performance.now() - current.last.at < 90 &&
            Math.hypot(...current.velocity) > 0.25
        ) {
            glide(current.velocity);
        }
    };

    const onKeyDown = (event: React.KeyboardEvent<SVGSVGElement>) => {
        const size = sizeRef.current;

        if (!size || event.target !== event.currentTarget) {
            return;
        }

        const current = viewRef.current;
        const step = 90;
        const moves: Record<string, Point> = {
            ArrowLeft: [step, 0],
            ArrowRight: [-step, 0],
            ArrowUp: [0, step],
            ArrowDown: [0, -step],
        };

        if (moves[event.key]) {
            event.preventDefault();
            fly(
                {
                    k: current.k,
                    x: current.x + moves[event.key][0],
                    y: current.y + moves[event.key][1],
                },
                200,
            );
        } else if (event.key === '+' || event.key === '=') {
            zoomAt([size.width / 2, size.height / 2], 1.4, true);
        } else if (event.key === '-' || event.key === '_') {
            zoomAt([size.width / 2, size.height / 2], 1 / 1.4, true);
        }
    };

    const current = useCallback(() => viewRef.current, []);

    return {
        view,
        size,
        dragging,
        current,
        lookAt,
        zoomAt,
        fit,
        handlers: {
            onPointerDown,
            onPointerMove,
            onPointerUp,
            onPointerCancel: onPointerUp,
            onKeyDown,
            // The right click is for asking what is there, not the browser's menu.
            onContextMenu: (event: React.MouseEvent) => event.preventDefault(),
        },
    };
}

/** Faction colour washed over the land each faction holds. */
const Territory = memo(function Territory({
    regions,
}: {
    regions: MapRegion[];
}) {
    return (
        <g pointerEvents="none">
            {/* Wild land a farm has grown into, cleared and planted. */}
            {regions
                .filter((region) => FIELDS[region.key] !== undefined)
                .map((region) => (
                    <FieldGround
                        key={`field-${region.id}`}
                        fieldKey={region.key}
                    />
                ))}
            {regions.map((region) => {
                const d = toPath(shapeFor(region).polygon);

                if (region.ring === 'safe') {
                    return (
                        <path
                            key={region.id}
                            d={d}
                            fill="#41d1ff"
                            fillOpacity={0.05}
                        />
                    );
                }

                return region.faction ? (
                    <path
                        key={region.id}
                        d={d}
                        fill={region.faction.colour}
                        fillOpacity={0.13}
                    />
                ) : null;
            })}
        </g>
    );
});

/** Borders: faction edges, the House's gradient, raids, and what's selected. */
const Borders = memo(function Borders({
    regions,
    houseId,
    houseColour,
    houseBanner,
    selectedId,
}: {
    regions: MapRegion[];
    houseId: number | null;
    houseColour: string | null;
    houseBanner: BannerData | null;
    selectedId: number | null;
}) {
    const selected = regions.find((region) => region.id === selectedId);
    const [fx, fy] = (() => {
        const farmhouse = regions.find((region) => region.key === 'farmhouse');

        return farmhouse ? shapeFor(farmhouse).centre : [0, 0];
    })();

    return (
        <g pointerEvents="none">
            {regions.map((region) => {
                const d = toPath(shapeFor(region).polygon);
                const clip = `url(#clip-${region.key})`;

                return (
                    <g key={region.id}>
                        {region.faction && (
                            <path
                                d={d}
                                fill="none"
                                stroke={
                                    region.faction.id === houseId
                                        ? 'url(#house-edge)'
                                        : region.faction.colour
                                }
                                strokeWidth={
                                    region.faction.id === houseId ? 10 : 7
                                }
                                vectorEffect="non-scaling-stroke"
                                clipPath={clip}
                            />
                        )}
                        {region.ring === 'safe' && (
                            <path
                                d={d}
                                fill="none"
                                stroke="#41d1ff"
                                strokeOpacity={0.5}
                                strokeWidth={6}
                                vectorEffect="non-scaling-stroke"
                                clipPath={clip}
                            />
                        )}
                        {region.incoming.length > 0 && (
                            <path
                                d={d}
                                fill={FLAG}
                                fillOpacity={0.08}
                                stroke={FLAG}
                                strokeWidth={7}
                                vectorEffect="non-scaling-stroke"
                                clipPath={clip}
                                className="animate-pulse"
                            />
                        )}
                    </g>
                );
            })}
            {/* The House's flag over the Farmhouse. */}
            <g
                transform={`translate(${(fx + 58).toFixed(1)} ${(fy - 40).toFixed(1)})`}
            >
                <circle r={3} fill="#cfc8dd" />
                <line
                    x1={0}
                    y1={0}
                    x2={0}
                    y2={-38}
                    stroke="#cfc8dd"
                    strokeWidth={2.2}
                />
                {houseBanner ? (
                    <image
                        href={bannerUrl(houseBanner)}
                        x={1}
                        y={-38}
                        width={18}
                        height={24}
                        preserveAspectRatio="xMidYMin meet"
                    />
                ) : (
                    <path
                        d="M1 -38 L28 -31 L1 -23 Z"
                        fill={houseColour ?? '#6a6479'}
                        stroke={HALO}
                        strokeWidth={1}
                    />
                )}
            </g>
            {selected && (
                <>
                    <path
                        d={toPath(shapeFor(selected).polygon)}
                        fill="none"
                        stroke="#ffffff"
                        strokeOpacity={0.25}
                        strokeWidth={9}
                        vectorEffect="non-scaling-stroke"
                    />
                    <path
                        d={toPath(shapeFor(selected).polygon)}
                        fill="none"
                        stroke="#ffffff"
                        strokeWidth={2.5}
                        vectorEffect="non-scaling-stroke"
                    />
                </>
            )}
        </g>
    );
});

function quadAt(a: Point, c: Point, b: Point, t: number): Point {
    const u = 1 - t;

    return [
        u * u * a[0] + 2 * u * t * c[0] + t * t * b[0],
        u * u * a[1] + 2 * u * t * c[1] + t * t * b[1],
    ];
}

function March({
    from,
    to,
    colour,
    k,
}: {
    from: Point;
    to: Point;
    colour: string;
    k: number;
}) {
    const dx = to[0] - from[0];
    const dy = to[1] - from[1];
    const length = Math.hypot(dx, dy) || 1;
    const bend = length * 0.2;
    const control: Point = [
        (from[0] + to[0]) / 2 + (-dy / length) * bend,
        (from[1] + to[1]) / 2 + (dx / length) * bend,
    ];
    // Stop short of the target, on the same curve.
    const t = 1 - Math.min(0.3, 40 / length);
    const end = quadAt(from, control, to, t);
    const split: Point = [
        from[0] + (control[0] - from[0]) * t,
        from[1] + (control[1] - from[1]) * t,
    ];
    const angle =
        (Math.atan2(end[1] - split[1], end[0] - split[0]) * 180) / Math.PI;
    const d = `M${from[0].toFixed(1)} ${from[1].toFixed(1)}Q${split[0].toFixed(1)} ${split[1].toFixed(1)} ${end[0].toFixed(1)} ${end[1].toFixed(1)}`;

    return (
        <g>
            <path
                d={d}
                fill="none"
                stroke={colour}
                strokeOpacity={0.25}
                strokeWidth={12}
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
            />
            <path
                d={d}
                fill="none"
                stroke={colour}
                strokeWidth={4}
                strokeDasharray="10 8"
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
                className="map-march"
            />
            <g
                transform={`translate(${end[0].toFixed(1)} ${end[1].toFixed(1)}) rotate(${angle.toFixed(1)}) scale(${1 / k})`}
            >
                <path
                    d="M6 0L-12 -9L-7 0L-12 9Z"
                    fill={colour}
                    stroke={HALO}
                    strokeWidth={1}
                />
            </g>
        </g>
    );
}

/** Irrigation trenches, drawn as water running from its source to the farm. */
const Trenches = memo(function Trenches({ regions }: { regions: MapRegion[] }) {
    return (
        <g pointerEvents="none">
            {regions.flatMap((farm) => {
                if (!farm.trench) {
                    return [];
                }

                const points = farm.trench.keys.map((key) => hub(key));
                const d = smoothPath(points);

                return [
                    <g key={farm.id}>
                        <path
                            d={d}
                            fill="none"
                            stroke="#3b2a1a"
                            strokeOpacity={0.7}
                            strokeWidth={9}
                            strokeLinecap="round"
                            vectorEffect="non-scaling-stroke"
                        />
                        <path
                            d={d}
                            fill="none"
                            stroke="#5cc8ff"
                            strokeWidth={4}
                            strokeLinecap="round"
                            vectorEffect="non-scaling-stroke"
                        />
                    </g>,
                ];
            })}
        </g>
    );
});

/** Raids on the march, drawn as arrows from the attackers' land. */
const Marches = memo(function Marches({
    regions,
    k,
    gateNight,
}: {
    regions: MapRegion[];
    k: number;
    gateNight: boolean;
}) {
    const byId = new Map(regions.map((region) => [region.id, region]));

    return (
        <g pointerEvents="none">
            {regions.flatMap((target) =>
                target.incoming.map((attack) => {
                    const from =
                        attack.from !== null
                            ? byId.get(attack.from)
                            : undefined;

                    return from ? (
                        <March
                            key={attack.id}
                            from={anchor(from.key, target.key)}
                            to={anchor(target.key, from.key)}
                            colour={attack.faction.colour}
                            k={k}
                        />
                    ) : null;
                }),
            )}
            {gateNight && (
                <March
                    from={hub('irongate_garrison')}
                    to={hub('ironfield_gate')}
                    colour={FLAG}
                    k={k}
                />
            )}
        </g>
    );
});

/** What each farm beyond the fences grows, as a sign on its label. */
const CROPS: Record<string, string> = {
    honey: '🍯',
    herbs: '🌿',
    iron: '⚒',
    gold: '🪙',
    copper: '🟤',
    pears: '🍐',
    carrots: '🥕',
    berries: '🫐',
    pumpkins: '🎃',
};

function capitalised(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Label size in screen pixels: grows a little as you zoom in. */
export function labelPixels(k: number): number {
    return 11.5 * (k / 0.6) ** 0.25;
}

export function subtitle(
    region: MapRegion,
    houseId: number | null,
    gateNight: boolean,
    gateResult: GateResult,
): { text: string; colour: string } | null {
    if (region.incoming.length > 0) {
        const first = [...region.incoming].sort((a, b) =>
            a.landsAt.localeCompare(b.landsAt),
        )[0];

        return {
            text: `⚔ Raid lands ${clockTime(first.landsAt)}`,
            colour: '#f5a27a',
        };
    }

    if (region.plunderers) {
        return {
            text: `🏴 ${region.plunderers.men} plunderers strike ${clockTime(region.plunderers.landsAt)}`,
            colour: '#f5a27a',
        };
    }

    if (gateNight && region.key === 'ironfield_gate') {
        return { text: '⚔ Ironfield raids at 19:00', colour: '#f5a27a' };
    }

    if (gateResult && region.key === 'ironfield_gate') {
        return gateResult === 'held'
            ? { text: '🛡 The gate held tonight', colour: '#8fe3b0' }
            : { text: '🔥 Ironfield broke through', colour: '#f5a27a' };
    }

    if (region.ironfield) {
        return {
            text: `⚔ Depth ${region.ironfield.depth} · ${region.ironfield.men} men on guard`,
            colour: '#f5a27a',
        };
    }

    if (region.wateredUntil === '' && !region.irrigated) {
        return { text: '💧 Parched · needs water', colour: '#8fd3ff' };
    }

    if (region.ring === 'city') {
        return {
            text: CITY_LINES[region.key] ?? "The humans' town",
            colour: '#f5a27a',
        };
    }

    if (region.stock?.nextAt) {
        return {
            text: `⏳ Picked clean till ${clockTime(region.stock.nextAt)}`,
            colour: '#c8c3d4',
        };
    }

    if (region.gatedUntil) {
        return {
            text: `🔒 Gated till ${clockTime(region.gatedUntil)}`,
            colour: '#c8c3d4',
        };
    }

    if (region.settledUntil) {
        return {
            text: `🛡 Settled till ${clockTime(region.settledUntil)}`,
            colour: '#c8c3d4',
        };
    }

    const crop =
        region.ring === 'farm'
            ? `${CROPS[region.produces ?? ''] ?? '🌾'} `
            : '';

    if (region.faction) {
        return {
            text: `${region.faction.id === houseId ? '👑 ' : ''}${crop}${region.faction.name}`,
            colour: region.faction.colour,
        };
    }

    if (region.ring === 'farm') {
        return {
            text: `${crop}${capitalised(region.produces ?? 'corn')} · for sale or for the taking`,
            colour: '#ffd33d',
        };
    }

    if (region.ring === 'safe') {
        return {
            text: 'Safe ground · newcomers start here',
            colour: '#8fe3ff',
        };
    }

    if (region.key === 'farmhouse') {
        return { text: 'Seat of power · nobody rules', colour: '#c8c3d4' };
    }

    return region.produces
        ? {
              text:
                  region.produces === 'corn'
                      ? 'Corn'
                      : region.produces.charAt(0).toUpperCase() +
                        region.produces.slice(1),
              colour: '#a29db2',
          }
        : null;
}

export function labelPoint(region: MapRegion): Point {
    const shape = shapeFor(region);

    if (region.ring === 'safe') {
        return [650, shape.centre[1] - 14];
    }

    return [shape.centre[0], shape.centre[1] - 72];
}

/** How tonight's Gate Night went, once it has happened. */
export type GateResult = 'held' | 'fell' | null;

/** Region names and lane names, the same size on screen at any zoom. */
const Labels = memo(function Labels({
    regions,
    k,
    houseId,
    gateNight,
    gateResult,
}: {
    regions: MapRegion[];
    k: number;
    houseId: number | null;
    gateNight: boolean;
    gateResult: GateResult;
}) {
    const pixels = labelPixels(k);
    const lanes = k >= 0.5;

    return (
        <g pointerEvents="none" fontFamily="inherit">
            <defs>
                <path id="brook-label" d={smoothPath(BROOK.slice(1, 5))} />
            </defs>
            {lanes && (
                <text
                    fontSize={10 / k}
                    fontStyle="italic"
                    fill="#7fb2df"
                    stroke={HALO}
                    strokeWidth={3 / k}
                    paintOrder="stroke"
                    dy={-16 / k}
                >
                    <textPath
                        href="#brook-label"
                        startOffset="50%"
                        textAnchor="middle"
                    >
                        Hollow Brook
                    </textPath>
                </text>
            )}
            {(
                [
                    ['THE RIDGE', [800, -570], 0],
                    ['IRONFIELD', [2480, 480], 90],
                ] as const
            ).map(([text, [x, y], angle]) => (
                <text
                    key={text}
                    transform={`translate(${x} ${y}) rotate(${angle})`}
                    textAnchor="middle"
                    fontSize={Math.min(pixels / k, 26)}
                    fontWeight={800}
                    letterSpacing={(Math.min(pixels / k, 26) * 0.3).toFixed(1)}
                    fill={text === 'IRONFIELD' ? '#f5a27a' : '#6f6a7d'}
                    fillOpacity={0.8}
                    stroke={HALO}
                    strokeWidth={3 / k}
                    paintOrder="stroke"
                >
                    {text}
                </text>
            ))}
            {regions.map((region) => {
                const shape = shapeFor(region);
                const [x, y] = labelPoint(region);
                const name = region.name.toUpperCase();
                const room =
                    region.ring === 'safe' ? 420 : shape.box.width * 0.8;
                const font = Math.min(pixels / k, room / (name.length * 0.72));
                const sub = subtitle(region, houseId, gateNight, gateResult);

                return (
                    <g
                        key={region.id}
                        transform={`translate(${x.toFixed(1)} ${y.toFixed(1)})`}
                    >
                        <text
                            textAnchor="middle"
                            fontSize={font}
                            fontWeight={800}
                            letterSpacing={(font * 0.09).toFixed(2)}
                            fill={LABEL}
                            stroke={HALO}
                            strokeWidth={font * 0.3}
                            paintOrder="stroke"
                            strokeLinejoin="round"
                        >
                            {name}
                        </text>
                        {sub && (
                            <text
                                y={font * 1.2}
                                textAnchor="middle"
                                fontSize={font * 0.82}
                                fontWeight={700}
                                fill={sub.colour}
                                stroke={HALO}
                                strokeWidth={font * 0.26}
                                paintOrder="stroke"
                                strokeLinejoin="round"
                            >
                                {sub.text}
                            </text>
                        )}
                    </g>
                );
            })}
        </g>
    );
});

/** Spots round a region's hub for the animals standing there, in screen pixels. */
export const SPOTS: Point[] = [
    ...Array.from(
        { length: 6 },
        (_, i) =>
            [
                Math.cos((i / 6) * Math.PI * 2 + 0.3) * 36,
                Math.sin((i / 6) * Math.PI * 2 + 0.3) * 30,
            ] as Point,
    ),
    ...Array.from(
        { length: 10 },
        (_, i) =>
            [
                Math.cos((i / 10) * Math.PI * 2) * 62,
                Math.sin((i / 10) * Math.PI * 2) * 48,
            ] as Point,
    ),
    ...Array.from(
        { length: 14 },
        (_, i) =>
            [
                Math.cos((i / 14) * Math.PI * 2 + 0.2) * 90,
                Math.sin((i / 14) * Math.PI * 2 + 0.2) * 68,
            ] as Point,
    ),
];

/** How many others show round each hub at a zoom: more the closer in, up to 24. */
export function crowdRoom(k: number): number {
    return k < 0.55 ? 3 : k < 0.9 ? 6 : k < 1.8 ? 12 : 24;
}

/** Homes and buildings have a fixed size on the land: small far out, big and detailed close in. */
export const OBJECT_FLOOR = 1.2;

/** Past this zoom, animals and markers grow with the land instead of keeping their size on screen. */
export const CLOSE_ZOOM = 1.2;

/** How much to scale a screen-sized thing: the same on screen far out, growing with the land close in. */
export function grows(k: number): number {
    return 1 / Math.min(k, CLOSE_ZOOM);
}

/** The home a point on the map falls on at this zoom, yours first, else the nearest. */
export function myHomeAt(
    homes: MapHome[] | undefined,
    [x, y]: Point,
    k: number,
): MapHome | null {
    void k;
    const reach = 26 / OBJECT_FLOOR;
    let best: MapHome | null = null;
    let bestDistance = reach;

    for (const home of homes ?? []) {
        const distance = Math.hypot(home.x - x, home.y - y);

        if (home.mine && distance <= reach) {
            return home;
        }

        if (distance <= bestDistance) {
            best = home;
            bestDistance = distance;
        }
    }

    return best;
}

/** The other animals in the valley, standing where they are. */
const Crowd = memo(function Crowd({
    regions,
    k,
    wanted,
}: {
    regions: MapRegion[];
    k: number;
    /** Animals with a warrant out: always shown, marked for bounty hunters. */
    wanted: number[];
}) {
    const room = crowdRoom(k);

    return (
        <g pointerEvents="none">
            {regions.map((region) => {
                const others = region.animals.sample
                    .filter((animal) => !animal.me)
                    .sort(
                        (a, b) =>
                            Number(wanted.includes(b.id)) -
                            Number(wanted.includes(a.id)),
                    );
                const shown = others.slice(0, room);
                const extra =
                    region.animals.count -
                    region.animals.sample.filter((animal) => animal.me).length -
                    shown.length;
                const [hx, hy] = hub(region.key);

                return (
                    <g key={region.id}>
                        {shown.map((animal, i) => {
                            const [sx, sy] = SPOTS[i];

                            return (
                                <g
                                    key={animal.id}
                                    data-animal={animal.id}
                                    pointerEvents="all"
                                    className="cursor-pointer"
                                    transform={`translate(${(hx + sx * grows(k)).toFixed(1)} ${(hy + sy * grows(k)).toFixed(1)}) scale(${grows(k)})`}
                                >
                                    <circle
                                        r={11.5}
                                        fill="#14121a"
                                        stroke={
                                            wanted.includes(animal.id)
                                                ? FLAG
                                                : (animal.colour ?? '#8a8399')
                                        }
                                        strokeWidth={
                                            wanted.includes(animal.id)
                                                ? 3.2
                                                : 2.2
                                        }
                                    />
                                    <text
                                        textAnchor="middle"
                                        dominantBaseline="central"
                                        fontSize={13}
                                        y={0.5}
                                    >
                                        {speciesEmoji(animal.species)}
                                    </text>
                                    <HealthBar
                                        value={animal.health ?? 100}
                                        y={-17}
                                        width={22}
                                    />
                                    {wanted.includes(animal.id) && (
                                        <g transform="translate(10 -10)">
                                            <circle
                                                r={7}
                                                fill={FLAG}
                                                stroke={HALO}
                                                strokeWidth={1.2}
                                            />
                                            <text
                                                textAnchor="middle"
                                                dominantBaseline="central"
                                                fontSize={8}
                                                y={0.5}
                                            >
                                                🎯
                                            </text>
                                        </g>
                                    )}
                                </g>
                            );
                        })}
                        {extra > 0 && (
                            <g
                                transform={`translate(${(hx + SPOTS[Math.min(shown.length, SPOTS.length - 1)][0] * grows(k)).toFixed(1)} ${(hy + SPOTS[Math.min(shown.length, SPOTS.length - 1)][1] * grows(k)).toFixed(1)}) scale(${grows(k)})`}
                            >
                                <rect
                                    x={-15}
                                    y={-10}
                                    width={30}
                                    height={20}
                                    rx={10}
                                    fill="#2a253a"
                                    stroke="#4f4860"
                                />
                                <text
                                    textAnchor="middle"
                                    dominantBaseline="central"
                                    fontSize={10.5}
                                    fontWeight={800}
                                    fill="#ffffff"
                                >
                                    +{extra}
                                </text>
                            </g>
                        )}
                    </g>
                );
            })}
        </g>
    );
});

/** Every animal's home, in its skin's colours: a Hut, a Cottage or a Fort with battlements. */
const Homes = memo(function Homes({
    homes,
    k,
}: {
    homes: MapHome[];
    k: number;
}) {
    return (
        <g pointerEvents="none">
            {homes.map((home) => {
                const w = 16 + home.level * 5;
                const wall = home.skin?.colours[1] ?? '#cfc3ad';
                const roof = home.skin?.colours[0] ?? '#6a6479';

                if (home.fire === 'ash') {
                    return (
                        <g
                            key={home.id}
                            transform={`translate(${home.x.toFixed(1)} ${home.y.toFixed(1)}) scale(${HOME_SIZE / OBJECT_FLOOR})`}
                        >
                            <title>{`The ashes of ${home.owner.name}'s ${home.name}`}</title>
                            <Ashes w={w} />
                            <HealthBar value={0} y={-w * 0.55} width={w} />
                        </g>
                    );
                }

                return (
                    <g
                        key={home.id}
                        transform={`translate(${home.x.toFixed(1)} ${home.y.toFixed(1)}) scale(${HOME_SIZE / OBJECT_FLOOR})`}
                        opacity={home.readyAt ? 0.6 : 1}
                    >
                        <title>{`${home.owner.name}'s ${home.name}`}</title>
                        {home.level === 0 ? (
                            <>
                                <path
                                    d="M-11 8L0 -10L11 8z"
                                    fill={roof === '#6a6479' ? '#b9a57c' : roof}
                                    stroke={home.mine ? VIOLET : HALO}
                                    strokeWidth={home.mine ? 2.4 : 1.2}
                                    strokeDasharray={
                                        home.readyAt ? '3 2' : undefined
                                    }
                                />
                                <path d="M-2 8L0 0L2 8z" fill="#3a2a1e" />
                            </>
                        ) : (
                            <>
                                <rect
                                    x={-w / 2}
                                    y={-w * 0.3}
                                    width={w}
                                    height={w * 0.75}
                                    fill={wall}
                                    stroke={home.mine ? VIOLET : HALO}
                                    strokeWidth={home.mine ? 2.4 : 1.2}
                                    strokeDasharray={
                                        home.readyAt ? '3 2' : undefined
                                    }
                                />
                                {home.level === 3 ? (
                                    <path
                                        d={`M${-w / 2} ${-w * 0.3}v${-w * 0.3}h${w / 5}v${w * 0.15}h${w / 5}v${-w * 0.15}h${w / 5}v${w * 0.15}h${w / 5}v${-w * 0.15}h${w / 5}v${w * 0.3}z`}
                                        fill={roof}
                                        stroke={HALO}
                                        strokeWidth={1}
                                    />
                                ) : (
                                    <path
                                        d={`M${-w / 2 - 2} ${-w * 0.3}L0 ${-w * 0.85}L${w / 2 + 2} ${-w * 0.3}z`}
                                        fill={roof}
                                        stroke={HALO}
                                        strokeWidth={1}
                                    />
                                )}
                                <rect
                                    x={-w * 0.1}
                                    y={w * 0.1}
                                    width={w * 0.2}
                                    height={w * 0.35}
                                    fill="#3a2a1e"
                                />
                            </>
                        )}
                        {home.fire === 'burning' && <Flames w={w} />}
                        <HealthBar
                            value={home.health ?? 100}
                            y={-w * (home.level === 0 ? 0.75 : 1.05)}
                            width={Math.max(18, w)}
                        />
                    </g>
                );
            })}
        </g>
    );
});

/** A little bar over an animal or a home: its health, out of 100, for anyone to see. */
export function HealthBar({
    value,
    y,
    width,
}: {
    value: number;
    y: number;
    width: number;
}) {
    const fill = value > 60 ? '#22c55e' : value > 30 ? '#f59e0b' : '#ef4444';
    const v = Math.max(0, Math.min(100, value));

    return (
        <g transform={`translate(${(-width / 2).toFixed(1)} ${y.toFixed(1)})`}>
            <title>{`Health ${Math.round(v)}/100`}</title>
            <rect
                width={width}
                height={4}
                rx={2}
                fill="#14121a"
                stroke={HALO}
                strokeWidth={0.8}
            />
            <rect width={(width * v) / 100} height={4} rx={2} fill={fill} />
        </g>
    );
}

/** Ironfield's town from above: rooftops along each grey block, the names left clear (as on the 3D map). */
const IronfieldTown = memo(function IronfieldTown({
    regions,
}: {
    regions: MapRegion[];
}) {
    const ironfield = regions.filter((region) => region.ring === 'ironfield');
    const lots = useMemo(
        () => cityLots(ironfield, shapeFor, labelPoint),
        [ironfield.length], // eslint-disable-line react-hooks/exhaustive-deps
    );

    return (
        <g pointerEvents="none">
            {lots.map((lot, i) => {
                const tall = /^[46]Story/.test(lot.model);
                const mid = /^3Story|Balcony|Wide/.test(lot.model);
                const [w, h] = /Slim/.test(lot.model)
                    ? [26, 52]
                    : /Wide/.test(lot.model)
                      ? [52, 92]
                      : [50, 54];
                const roof = tall ? '#6c7380' : mid ? '#8a6f5a' : '#a3735c';

                return (
                    <g
                        key={i}
                        transform={`translate(${lot.x.toFixed(1)} ${lot.y.toFixed(1)}) rotate(${((lot.turn * 180) / Math.PI).toFixed(0)})`}
                    >
                        <rect
                            x={-w / 2 + 4}
                            y={-h / 2 + 5}
                            width={w}
                            height={h}
                            fill="#000"
                            opacity={0.35}
                        />
                        <rect
                            x={-w / 2}
                            y={-h / 2}
                            width={w}
                            height={h}
                            fill={roof}
                            stroke="#2b2b31"
                            strokeWidth={1.5}
                        />
                        <path
                            d={`M${-w / 2} 0H${w / 2}`}
                            stroke="#00000055"
                            strokeWidth={1.5}
                        />
                    </g>
                );
            })}
        </g>
    );
});

/** A phone: one flame per burning region instead of four, to keep the map quick. */
const PHONE =
    typeof window !== 'undefined' &&
    (window.matchMedia?.('(pointer: coarse)').matches ?? false);

/** Land a plunder, a raid or Irongate's riders set alight: flames, then ashes for hours. */
const LandFires = memo(function LandFires({
    regions,
}: {
    regions: MapRegion[];
}) {
    return (
        <g pointerEvents="none">
            {regions
                .filter((region) => region.fire)
                .map((region) => {
                    const [hx, hy] = hub(region.key);

                    return (
                        <g key={region.id}>
                            <title>
                                {region.fire === 'burning'
                                    ? `${region.name} is burning`
                                    : `Ashes where ${region.name} burned`}
                            </title>
                            {(PHONE
                                ? [[0, 18]]
                                : [
                                      [-34, 18],
                                      [26, 30],
                                      [6, -26],
                                      [-12, 44],
                                  ]
                            ).map(([dx, dy], i) => (
                                <g
                                    key={i}
                                    transform={`translate(${hx + dx} ${hy + dy})`}
                                >
                                    {region.fire === 'burning' ? (
                                        <Flames w={26 + (i % 2) * 8} />
                                    ) : (
                                        <Ashes w={24 + (i % 2) * 6} />
                                    )}
                                </g>
                            ))}
                        </g>
                    );
                })}
        </g>
    );
});

/** What dead animals left behind: sacks lying where they fell, for anyone to pick up. */
const LootPiles = memo(function LootPiles({
    loot,
    k,
}: {
    loot: LootPile[];
    k: number;
}) {
    return (
        <g>
            {loot.map((pile) => (
                <g
                    key={pile.id}
                    data-loot={pile.id}
                    className="cursor-pointer"
                    pointerEvents="all"
                    transform={`translate(${pile.x.toFixed(1)} ${pile.y.toFixed(1)}) scale(${grows(k)})`}
                >
                    <title>{`What ${pile.from ?? 'an animal'} left behind: tap to pick it up`}</title>
                    <circle r={13} fill="#f5c542" opacity={0.25}>
                        <animate
                            attributeName="r"
                            values="11;16;11"
                            dur="2s"
                            repeatCount="indefinite"
                        />
                    </circle>
                    <text
                        textAnchor="middle"
                        dominantBaseline="central"
                        fontSize={18}
                        y={0.5}
                    >
                        💰
                    </text>
                </g>
            ))}
        </g>
    );
});

/** Flames licking over a home, flickering, with smoke rolling off. */
function Flames({ w }: { w: number }) {
    const tongues = [-0.35, -0.12, 0.1, 0.32];

    return (
        <g>
            <title>On fire</title>
            <ellipse
                rx={w * 0.9}
                ry={w * 0.35}
                cy={w * 0.4}
                fill="#ff7a1a"
                opacity={0.3}
            >
                <animate
                    attributeName="opacity"
                    values="0.2;0.4;0.25;0.35;0.2"
                    dur="0.9s"
                    repeatCount="indefinite"
                />
            </ellipse>
            {tongues.map((at, i) => (
                <g
                    key={at}
                    transform={`translate(${(at * w).toFixed(1)} ${(w * 0.25).toFixed(1)})`}
                >
                    <path
                        d="M0 0C-6 -4 -7 -12 -2 -20C-2 -14 2 -14 1 -24C7 -16 8 -6 0 0z"
                        fill="#ff6a13"
                    >
                        <animateTransform
                            attributeName="transform"
                            type="scale"
                            values={
                                i % 2
                                    ? '1 1;1.1 1.4;0.9 1.1;1 1'
                                    : '1 1.2;0.9 0.9;1.1 1.5;1 1.2'
                            }
                            dur={`${0.6 + i * 0.13}s`}
                            repeatCount="indefinite"
                        />
                    </path>
                    <path
                        d="M0 -1C-3 -3 -4 -8 -1 -12C0 -8 2 -9 1 -14C4 -9 4 -4 0 -1z"
                        fill="#ffd34d"
                    >
                        <animateTransform
                            attributeName="transform"
                            type="scale"
                            values="1 1;1.1 1.5;0.9 1;1 1"
                            dur={`${0.5 + i * 0.11}s`}
                            repeatCount="indefinite"
                        />
                    </path>
                </g>
            ))}
            {[0, 1, 2].map((i) => (
                <circle key={i} r={4} fill="#4a4442" opacity={0}>
                    <animate
                        attributeName="cy"
                        values={`${-w * 0.6};${-w * 2}`}
                        dur="2.4s"
                        begin={`${i * 0.8}s`}
                        repeatCount="indefinite"
                    />
                    <animate
                        attributeName="r"
                        values="3;9"
                        dur="2.4s"
                        begin={`${i * 0.8}s`}
                        repeatCount="indefinite"
                    />
                    <animate
                        attributeName="opacity"
                        values="0;0.55;0"
                        dur="2.4s"
                        begin={`${i * 0.8}s`}
                        repeatCount="indefinite"
                    />
                </circle>
            ))}
        </g>
    );
}

/** What a burned-down home leaves: a grey heap, fallen beams and embers still glowing. */
function Ashes({ w }: { w: number }) {
    return (
        <g>
            <ellipse
                rx={w * 0.65}
                ry={w * 0.32}
                cy={w * 0.2}
                fill="#6f6a66"
                stroke="#2a2522"
                strokeWidth={1}
            />
            <ellipse
                rx={w * 0.35}
                ry={w * 0.15}
                cx={-w * 0.1}
                cy={w * 0.15}
                fill="#9c9792"
            />
            <path
                d={`M${-w * 0.5} ${w * 0.1}L${w * 0.3} ${w * 0.3}M${-w * 0.2} ${w * 0.35}L${w * 0.45} ${w * 0.02}M${-w * 0.35} ${-w * 0.1}V${w * 0.15}`}
                stroke="#2a2522"
                strokeWidth={2.4}
                strokeLinecap="round"
            />
            {[-0.25, 0.1, 0.3].map((at, i) => (
                <circle
                    key={at}
                    cx={at * w}
                    cy={w * (0.12 + i * 0.07)}
                    r={1.6}
                    fill="#ff5a1f"
                >
                    <animate
                        attributeName="opacity"
                        values="0.3;1;0.3"
                        dur={`${1.4 + i * 0.5}s`}
                        repeatCount="indefinite"
                    />
                </circle>
            ))}
        </g>
    );
}

/** How big homes and tents are drawn, so they stand out on the map. */
const HOME_SIZE = 2.4;

/** What each of Irongate's districts is for, under its name. */
const CITY_LINES: Record<string, string> = {
    irongate_rookery: '🕯 Outlaws and lone wolves lie low',
    irongate_yards: '⚖ Ma Vetch and Wick deal here',
    irongate_garrison: "⚔ Ironfield's men muster here",
    irongate_smithy: '⚒ Bram works stone into iron',
    irongate_tavern: '🍲 Hot meals at the Black Dog',
    irongate_gallows: '🪢 Drills and fortunes',
};

/** Irongate's people, standing in their districts, tapped for their card. */
const Folk = memo(function Folk({
    folk,
    k,
}: {
    folk: FolkPerson[];
    k: number;
}) {
    return (
        <g>
            {folk.map((person) => {
                const [hx, hy] = hub(person.district);
                const x = hx + person.spot[0];
                const y = hy + person.spot[1];

                return (
                    <g
                        key={person.key}
                        data-folk={person.key}
                        className="cursor-pointer"
                        transform={`translate(${x.toFixed(1)} ${y.toFixed(1)}) scale(${1 / OBJECT_FLOOR})`}
                    >
                        <title>{`${person.name}, ${person.role}`}</title>
                        <circle
                            r={13}
                            fill="#2a1f1c"
                            stroke="#f5a27a"
                            strokeWidth={2.2}
                        />
                        <text
                            textAnchor="middle"
                            dominantBaseline="central"
                            fontSize={14}
                            y={0.5}
                        >
                            {folkFace(person.key)}
                        </text>
                        {k >= 0.7 && (
                            <text
                                y={24}
                                textAnchor="middle"
                                fontSize={9.5}
                                fontWeight={800}
                                fill="#f5d6c4"
                                stroke={HALO}
                                strokeWidth={3}
                                paintOrder="stroke"
                            >
                                {person.name.split(' ').slice(-1)[0]}
                            </text>
                        )}
                    </g>
                );
            })}
        </g>
    );
});

export const BUILDING_GLYPH: Record<BuildingKindValue, string> = {
    den: '🏠',
    watchtower: '🗼',
    drill_yard: '⚔️',
    storehouse: '📦',
    workshop: '🔨',
    head_office: '🏛️',
    mill: '🌬️',
    well: '🪣',
    coop: '🐔',
    silo: '🌾',
    hospital: '🏥',
};

/** Faction buildings, a badge in the holder's colour on each plot. */
const Bases = memo(function Bases({
    regions,
    k,
}: {
    regions: MapRegion[];
    k: number;
}) {
    return (
        <g pointerEvents="none">
            {regions.map((region) => {
                const building = region.building;

                if (!building || !region.faction) {
                    return null;
                }

                const [x, y] = plot(region.key);
                const goingUp = building.readyAt !== null;

                return (
                    <g
                        key={region.id}
                        transform={`translate(${x.toFixed(1)} ${y.toFixed(1)}) scale(${(1 / OBJECT_FLOOR).toFixed(3)})`}
                        opacity={goingUp ? 0.75 : 1}
                    >
                        <rect
                            x={-17}
                            y={-17}
                            width={34}
                            height={34}
                            rx={building.kind === 'den' ? 17 : 8}
                            fill="#14121a"
                            stroke={region.faction.colour}
                            strokeWidth={building.kind === 'den' ? 3.5 : 2.5}
                            strokeDasharray={goingUp ? '5 4' : undefined}
                        />
                        <text
                            textAnchor="middle"
                            dominantBaseline="central"
                            fontSize={17}
                            y={0.5}
                        >
                            {BUILDING_GLYPH[building.kind]}
                        </text>
                        {region.faction.banner && (
                            <g transform="translate(19 -30)">
                                <line
                                    x1={0}
                                    y1={0}
                                    x2={0}
                                    y2={30}
                                    stroke="#cfc8dd"
                                    strokeWidth={1.6}
                                />
                                <image
                                    href={bannerUrl(region.faction.banner)}
                                    x={1}
                                    y={0}
                                    width={13}
                                    height={17}
                                    preserveAspectRatio="xMidYMin meet"
                                />
                            </g>
                        )}
                        <g transform="translate(13 13)">
                            <circle
                                r={8}
                                fill={region.faction.colour}
                                stroke="#14121a"
                                strokeWidth={1.5}
                            />
                            <text
                                textAnchor="middle"
                                dominantBaseline="central"
                                fontSize={10}
                                fontWeight={900}
                                fill="#14121a"
                            >
                                {building.level}
                            </text>
                        </g>
                    </g>
                );
            })}
        </g>
    );
});

/** Your animal. It walks to its new region when you move. */
/** A clock that ticks while you walk, so your animal moves along its line. */
function useWalkClock(walk: MapMe['walk']): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!walk) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 250);

        return () => window.clearInterval(timer);
    }, [walk]);

    return now;
}

/** Where you are now: along your walk's line, or where you stand. */
export function walkPoint(me: MapMe, now: number): Point {
    if (!me.walk) {
        return [me.x, me.y];
    }

    const start = new Date(me.walk.startedAt).getTime();
    const end = new Date(me.walk.arrivesAt).getTime();
    const done = Math.min(
        1,
        Math.max(0, (now - start) / Math.max(1, end - start)),
    );

    return [
        me.walk.from[0] + (me.walk.to[0] - me.walk.from[0]) * done,
        me.walk.from[1] + (me.walk.to[1] - me.walk.from[1]) * done,
    ];
}

/** The straight line still to walk, and a flag where it ends. */
/**
 * Everyone else out walking: a dashed trail in their faction's colour, and
 * them moving along it by the clock, as your own walk shows.
 */
const OtherWalks = memo(function OtherWalks({
    walkers,
    k,
}: {
    walkers: Walker[];
    k: number;
}) {
    // Once a second they move on; the transform eases between ticks.
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    return (
        <g pointerEvents="none">
            {walkers.map((walker) => {
                const start = Date.parse(walker.startedAt);
                const end = Date.parse(walker.arrivesAt);
                const done = Math.min(
                    1,
                    Math.max(0, (now - start) / Math.max(1, end - start)),
                );
                const x =
                    walker.from[0] + (walker.to[0] - walker.from[0]) * done;
                const y =
                    walker.from[1] + (walker.to[1] - walker.from[1]) * done;
                const colour = walker.colour ?? '#c9c2d8';

                return (
                    <g key={`${walker.id}:${walker.arrivesAt}`}>
                        <title>{`${walker.name} is walking`}</title>
                        <line
                            x1={walker.from[0]}
                            y1={walker.from[1]}
                            x2={walker.to[0]}
                            y2={walker.to[1]}
                            stroke={colour}
                            strokeWidth={2.4 / k}
                            strokeDasharray={`${7 / k} ${6 / k}`}
                            strokeLinecap="round"
                            opacity={0.75}
                        />
                        <g
                            transform={`translate(${walker.to[0]} ${walker.to[1]}) scale(${grows(k)})`}
                        >
                            <circle
                                r={5}
                                fill={colour}
                                stroke={HALO}
                                strokeWidth={1.5}
                                opacity={0.85}
                            />
                        </g>
                        <g
                            style={{
                                transform: `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px)`,
                                transition: 'transform 1s linear',
                            }}
                        >
                            <g transform={`scale(${grows(k)})`}>
                                <circle
                                    r={11}
                                    fill="#14121a"
                                    stroke={colour}
                                    strokeWidth={2.2}
                                />
                                <text
                                    textAnchor="middle"
                                    dominantBaseline="central"
                                    fontSize={12}
                                    y={0.5}
                                >
                                    {speciesEmoji(walker.species)}
                                </text>
                            </g>
                        </g>
                    </g>
                );
            })}
        </g>
    );
});

/** Where a moving thing is now, by the clock, between when it set off and when it lands. */
function along(
    from: Point,
    to: Point,
    start: string,
    end: string,
    now: number,
): Point {
    const s = Date.parse(start);
    const e = Date.parse(end);
    const done = Math.min(1, Math.max(0, (now - s) / Math.max(1, e - s)));

    return [
        from[0] + (to[0] - from[0]) * done,
        from[1] + (to[1] - from[1]) * done,
    ];
}

/**
 * Red arrows: a robbery or a burning coming at you, or one of yours on its
 * way. The head moves along by the clock, faster the faster its maker walks.
 */
const Strikes = memo(function Strikes({
    strikes,
    k,
}: {
    strikes: Strike[];
    k: number;
}) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 500);

        return () => window.clearInterval(timer);
    }, []);

    return (
        <g pointerEvents="none">
            {strikes.map((strike) => {
                const [x, y] = along(
                    strike.from,
                    strike.to,
                    strike.startedAt,
                    strike.landsAt,
                    now,
                );
                const angle =
                    (Math.atan2(
                        strike.to[1] - strike.from[1],
                        strike.to[0] - strike.from[0],
                    ) *
                        180) /
                    Math.PI;
                const colour = '#ef4444';
                const label = strike.mine
                    ? `${strike.kind === 'rob' ? 'Robbing' : 'Burning'} ${strike.who ?? ''}`
                    : `${strike.who ?? 'Someone'} is coming ${strike.kind === 'rob' ? 'to rob you' : 'to burn your home'}`;

                return (
                    <g key={strike.id}>
                        <title>{label}</title>
                        <line
                            x1={strike.from[0]}
                            y1={strike.from[1]}
                            x2={strike.to[0]}
                            y2={strike.to[1]}
                            stroke={colour}
                            strokeWidth={(strike.mine ? 3 : 4) / k}
                            strokeDasharray={
                                strike.mine ? `${10 / k} ${6 / k}` : undefined
                            }
                            strokeLinecap="round"
                            opacity={0.85}
                        >
                            {!strike.mine && (
                                <animate
                                    attributeName="opacity"
                                    values="0.45;1;0.45"
                                    dur="1.2s"
                                    repeatCount="indefinite"
                                />
                            )}
                        </line>
                        <g
                            transform={`translate(${strike.to[0]} ${strike.to[1]}) scale(${grows(k)})`}
                        >
                            <circle
                                r={14}
                                fill="none"
                                stroke={colour}
                                strokeWidth={2.5}
                            >
                                <animate
                                    attributeName="r"
                                    values="8;18;8"
                                    dur="1.4s"
                                    repeatCount="indefinite"
                                />
                            </circle>
                        </g>
                        <g
                            style={{
                                transform: `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px)`,
                                transition: 'transform 0.5s linear',
                            }}
                        >
                            <g transform={`scale(${grows(k)})`}>
                                <path
                                    d="M14 0L-8 -10L-3 0L-8 10z"
                                    fill={colour}
                                    stroke={HALO}
                                    strokeWidth={1.5}
                                    transform={`rotate(${angle.toFixed(1)})`}
                                />
                                <text
                                    y={-16}
                                    textAnchor="middle"
                                    fontSize={10.5}
                                    fontWeight={800}
                                    fill={colour}
                                    stroke={HALO}
                                    strokeWidth={3}
                                    paintOrder="stroke"
                                >
                                    {label}
                                </text>
                            </g>
                        </g>
                    </g>
                );
            })}
        </g>
    );
});

function WalkLine({ from, to, k }: { from: Point; to: Point; k: number }) {
    return (
        <g pointerEvents="none">
            <line
                x1={from[0]}
                y1={from[1]}
                x2={to[0]}
                y2={to[1]}
                stroke={VIOLET}
                strokeWidth={3 / k}
                strokeDasharray={`${8 / k} ${6 / k}`}
                strokeLinecap="round"
                opacity={0.9}
            />
            <g transform={`translate(${to[0]} ${to[1]}) scale(${grows(k)})`}>
                <circle r={7} fill={VIOLET} stroke={HALO} strokeWidth={2} />
                <circle r={2.5} fill="#14121a" />
            </g>
        </g>
    );
}

function Me({
    at,
    species,
    k,
    walking,
    health,
}: {
    at: Point;
    species: Species;
    k: number;
    walking: boolean;
    health: number | null;
}) {
    return (
        <g
            pointerEvents="none"
            style={{
                transform: `translate(${at[0].toFixed(1)}px, ${at[1].toFixed(1)}px)`,
                // While walking the clock moves it; a jump eases into place.
                transition: walking
                    ? 'transform 0.25s linear'
                    : 'transform 1s cubic-bezier(0.45, 0, 0.2, 1)',
            }}
        >
            <g transform={`scale(${grows(k)})`}>
                <circle r={20} fill={VIOLET} className="map-ping" />
                <circle cx={1.5} cy={3} r={20} fill={HALO} opacity={0.6} />
                <circle r={20} fill={VIOLET} />
                <circle r={16.5} fill="#14121a" />
                <text
                    textAnchor="middle"
                    dominantBaseline="central"
                    fontSize={19}
                    y={0.5}
                >
                    {speciesEmoji(species)}
                </text>
                {health !== null && (
                    <HealthBar value={health} y={-27} width={34} />
                )}
                <g transform="translate(0 31)">
                    <rect
                        x={-18}
                        y={-8.5}
                        width={36}
                        height={17}
                        rx={8.5}
                        fill={VIOLET}
                        stroke={HALO}
                        strokeWidth={1.2}
                    />
                    <text
                        textAnchor="middle"
                        dominantBaseline="central"
                        fontSize={10}
                        fontWeight={900}
                        fill="#14121a"
                        letterSpacing={0.8}
                    >
                        YOU
                    </text>
                </g>
            </g>
        </g>
    );
}

/** See-through shapes over each region that take taps, hovers and the keyboard. */
const HitAreas = memo(function HitAreas({
    regions,
    selectedId,
    onPick,
}: {
    regions: MapRegion[];
    selectedId: number | null;
    onPick: (id: number) => void;
}) {
    return (
        <g>
            {regions.map((region) => (
                <path
                    key={region.id}
                    d={toPath(shapeFor(region).polygon)}
                    data-region={region.id}
                    role="button"
                    tabIndex={0}
                    aria-label={`${region.name}${region.faction ? `, held by ${region.faction.name}` : ''}`}
                    aria-pressed={region.id === selectedId}
                    pointerEvents="all"
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            onPick(region.id);
                        }
                    }}
                    className="cursor-pointer fill-white/0 transition-[fill] duration-150 outline-none hover:fill-white/[0.06] focus-visible:fill-white/10"
                />
            ))}
        </g>
    );
});

export function Compass() {
    return (
        <svg viewBox="-20 -27 40 47" className="h-12 w-10" aria-hidden>
            <circle r={17} fill="#14121a" fillOpacity={0.85} stroke="#4f4860" />
            <path d="M0 -13 L4.5 0 L0 2.5 L-4.5 0 Z" fill={VIOLET} />
            <path d="M0 13 L4.5 0 L0 -2.5 L-4.5 0 Z" fill="#6f6881" />
            <text
                y={-20}
                textAnchor="middle"
                fontSize={8.5}
                fontWeight={800}
                fill="#efecf7"
            >
                N
            </text>
        </svg>
    );
}

export type WorldMapProps = {
    valley: string;
    regions: MapRegion[];
    here: number | null;
    house: FactionRef | null;
    species: Species | null;
    selectedId: number | null;
    onSelect: (id: number | null) => void;
    gateNight: boolean;
    /** Where you stand, and the walk you're on. */
    me: MapMe | null;
    /** Walk to a point on the map. */
    onWalk?: (point: Point) => void;
    /** A right click or long press on the open scrub, to walk or set up camp there. */
    onGround?: (point: Point) => void;
    /** A tap, hold or right click on your own home, to open its card. */
    onHome?: (home: MapHome) => void;
    /** How tonight's Gate Night went, once it has happened. */
    gateResult: GateResult;
    /** The animals with a warrant out for their arrest. */
    wanted: number[];
    /** A button to switch between the flat map and the 3D one. */
    switcher?: ReactNode;
    /** Irongate's people, drawn in the town and tapped for their card. */
    irongate?: IrongateInfo;
    /** Open warrants, for the informer's card. */
    warrants?: Warrant[];
    /** Every animal's home. */
    homes?: MapHome[];
    /** What dead animals left lying about. */
    loot?: LootPile[];
    /** A tap on a pile of loot. */
    onLoot?: (pile: LootPile) => void;
    /** Your own health, for the bar over you. */
    myHealth?: number | null;
    /** Everyone else out walking, with their trails. */
    walkers?: Walker[];
    /** Robberies and burnings on their way, to you or from you. */
    strikes?: Strike[];
    children?: ReactNode;
};

/**
 * Hollowmere as a street map you can drag, zoom and tap: who holds what,
 * where every animal stands, what is marching where, and you.
 */
export function WorldMap({
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
    onGround,
    onHome,
    gateResult,
    wanted,
    switcher,
    irongate,
    warrants,
    homes,
    loot,
    onLoot,
    myHealth,
    walkers,
    strikes,
    children,
}: WorldMapProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const byId = useMemo(
        () => new Map(regions.map((region) => [region.id, region])),
        [regions],
    );
    const hereRegion = here !== null ? (byId.get(here) ?? null) : null;
    const selected =
        selectedId !== null ? (byId.get(selectedId) ?? null) : null;
    const now = useWalkClock(me?.walk ?? null);
    const herePoint: Point | null = me
        ? walkPoint(me, now)
        : hereRegion
          ? hub(hereRegion.key)
          : null;

    // The zoom, for the handlers below: how near a tap must be to your home.
    const kRef = useRef(1);

    // A quick tap on land sets off straight for that spot, the open scrub between the regions included; on your home, its card.
    const onTap = useCallback(
        (target: Element | null, world: Point) => {
            const home = myHomeAt(homes, world, kRef.current);
            if (home && onHome) {
                onHome(home);
                return;
            }
            const id = Number(target?.getAttribute('data-region'));

            if (
                onWalk &&
                ((Number.isFinite(id) && id > 0) || reachable(world))
            ) {
                onWalk(world);
            }
        },
        [onWalk, onHome, homes],
    );

    // A long press or a right click opens the card for what is there: your home's, a region's, or the open scrub's.
    const onInfo = useCallback(
        (target: Element | null, world: Point) => {
            const home = myHomeAt(homes, world, kRef.current);
            if (home && onHome) {
                onHome(home);
                return;
            }
            const id = Number(target?.getAttribute('data-region'));
            const region = Number.isFinite(id) && id > 0 ? id : null;
            onSelect(region);

            if (region === null && reachable(world)) {
                onGround?.(world);
            }
        },
        [onSelect, onGround, onHome, homes],
    );

    // A tap, hold or right click on another animal opens its menu; on one of Irongate's people, their card.
    const [menu, setMenu] = useState<{ animal: MapAnimal; at: Point } | null>(
        null,
    );
    const [person, setPerson] = useState<{
        folk: FolkPerson;
        at: Point;
    } | null>(null);
    const onAnimal = useCallback(
        (element: Element, at: Point) => {
            const lootId = element.getAttribute('data-loot');

            if (lootId) {
                const pile = loot?.find((p) => p.id === Number(lootId));
                setMenu(null);
                setPerson(null);
                if (pile) {
                    onLoot?.(pile);
                }

                return;
            }

            const folkKey = element.getAttribute('data-folk');

            if (folkKey) {
                const folk = irongate?.folk.find((f) => f.key === folkKey);
                setMenu(null);
                setPerson(folk ? { folk, at } : null);

                return;
            }

            setPerson(null);
            const id = Number(element.getAttribute('data-animal'));
            const animal = regions
                .flatMap((region) => region.animals.sample)
                .find((a) => a.id === id);
            setMenu(animal ? { animal, at } : null);
        },
        [regions, irongate, loot, onLoot],
    );

    const camera = useCamera(
        containerRef,
        svgRef,
        herePoint,
        onTap,
        onInfo,
        onAnimal,
    );
    const { view, size, lookAt, current } = camera;
    kRef.current = view.k;

    // A card opened by a tap points at that spot; once the map moves, it would point at the wrong thing.
    useEffect(() => {
        setMenu(null);
        setPerson(null);
    }, [view.x, view.y, view.k]);
    const ready = size !== null && view.k > 0;

    // Bring a newly picked region, or your animal after a walk, out from under the card.
    const bringIntoView = useCallback(
        (point: Point | null, panelOpen: boolean) => {
            if (!point || !size) {
                return;
            }

            const area = openArea(size, panelOpen);
            const { x, y, k: zoom } = current();
            const sx = point[0] * zoom + x;
            const sy = point[1] * zoom + y;

            if (
                sx < area.left ||
                sx > area.right ||
                sy < area.top ||
                sy > area.bottom
            ) {
                lookAt(point, zoom, [
                    (area.left + area.right) / 2,
                    (area.top + area.bottom) / 2,
                ]);
            }
        },
        [size, lookAt, current],
    );

    useEffect(() => {
        if (selected) {
            bringIntoView(shapeFor(selected).centre, true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedId]);

    useEffect(() => {
        if (herePoint) {
            bringIntoView(herePoint, selected !== null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [here, me?.walk?.arrivesAt]);

    const pick = useCallback((id: number) => onSelect(id), [onSelect]);
    const k = view.k || 1;
    const scale = (() => {
        // A scale bar of a round number of paces, about 90 pixels long.
        const paces = [25, 50, 100, 200, 400].find((p) => p * k >= 70) ?? 400;

        return { paces, width: paces * k };
    })();

    return (
        <div
            ref={containerRef}
            className="dark relative h-[calc(100svh-15rem)] min-h-[400px] w-full overflow-hidden rounded-2xl border border-[#2e2e32] bg-[#0b3a5c] text-foreground md:h-[calc(100svh-10.5rem)] md:min-h-[520px]"
        >
            <svg
                ref={svgRef}
                className={cn(
                    'absolute inset-0 size-full touch-none outline-none select-none [-webkit-touch-callout:none]',
                    camera.dragging ? 'cursor-grabbing' : 'cursor-grab',
                )}
                role="group"
                aria-label={`Map of ${valley}. Drag to look around, scroll or pinch to zoom, tap a spot to walk there, and press and hold or right-click to see what's there.`}
                tabIndex={0}
                {...camera.handlers}
                onPointerDownCapture={() => {
                    setMenu(null);
                    setPerson(null);
                }}
            >
                <defs>
                    <linearGradient id="house-edge" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stopColor="#41d1ff" />
                        <stop offset="0.5" stopColor="#b39aff" />
                        <stop offset="1" stopColor="#d18cff" />
                    </linearGradient>
                </defs>
                {ready && (
                    <g
                        transform={`translate(${view.x.toFixed(2)} ${view.y.toFixed(2)}) scale(${view.k.toFixed(4)})`}
                    >
                        <MapGround />
                        <Territory regions={regions} />
                        <MapFeatures />
                        <Borders
                            regions={regions}
                            houseId={house?.id ?? null}
                            houseColour={house?.colour ?? null}
                            houseBanner={house?.banner ?? null}
                            selectedId={selectedId}
                        />
                        <HitAreas
                            regions={regions}
                            selectedId={selectedId}
                            onPick={pick}
                        />
                        <Trenches regions={regions} />
                        <Marches
                            regions={regions}
                            k={k}
                            gateNight={gateNight}
                        />
                        <Labels
                            regions={regions}
                            k={k}
                            houseId={house?.id ?? null}
                            gateNight={gateNight}
                            gateResult={gateResult}
                        />
                        <Bases regions={regions} k={k} />
                        <IronfieldTown regions={regions} />
                        <LandFires regions={regions} />
                        {homes && <Homes homes={homes} k={k} />}
                        {loot && loot.length > 0 && (
                            <LootPiles loot={loot} k={k} />
                        )}
                        {irongate && <Folk folk={irongate.folk} k={k} />}
                        <Crowd regions={regions} k={k} wanted={wanted} />
                        {walkers && walkers.length > 0 && (
                            <OtherWalks walkers={walkers} k={k} />
                        )}
                        {strikes && strikes.length > 0 && (
                            <Strikes strikes={strikes} k={k} />
                        )}
                        {me?.walk && herePoint && (
                            <WalkLine from={herePoint} to={me.walk.to} k={k} />
                        )}
                        {herePoint && species && (
                            <Me
                                at={herePoint}
                                species={species}
                                k={k}
                                walking={me?.walk != null}
                                health={myHealth ?? null}
                            />
                        )}
                    </g>
                )}
            </svg>

            <div className="pointer-events-none absolute top-3 right-3 flex flex-col gap-2">
                <div className="pointer-events-auto flex flex-col overflow-hidden rounded-xl border bg-card/90 shadow-lg backdrop-blur">
                    <MapButton
                        label="Zoom in"
                        onClick={() =>
                            size &&
                            camera.zoomAt(
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
                            size &&
                            camera.zoomAt(
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
                    {herePoint && (
                        <MapButton
                            label="Find my animal"
                            onClick={() =>
                                lookAt(herePoint, Math.max(view.k, 0.95))
                            }
                        >
                            <LocateFixed className="size-4" />
                        </MapButton>
                    )}
                    <MapButton
                        label="See the whole valley"
                        onClick={camera.fit}
                        divided={herePoint !== null}
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
                    <span>{scale.paces} paces</span>
                    <span
                        className="h-1.5 border-x-2 border-b-2 border-muted-foreground"
                        style={{ width: scale.width }}
                    />
                </div>
                <Compass />
            </div>

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

            {children}
        </div>
    );
}

export function MapButton({
    label,
    onClick,
    divided = false,
    children,
}: {
    label: string;
    onClick: () => void;
    divided?: boolean;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            className={cn(
                'flex size-10 items-center justify-center text-foreground transition-colors hover:bg-accent',
                divided && 'border-t',
            )}
        >
            {children}
        </button>
    );
}
