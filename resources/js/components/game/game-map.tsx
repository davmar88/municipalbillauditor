import { Component, lazy, Suspense } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { toast } from 'sonner';
import type { WorldMapProps } from '@/components/game/world-map';
import { MapButton, WorldMap } from '@/components/game/world-map';
import { useMapView } from '@/hooks/use-map-view';
import { WeatherOverlay } from '@/components/game/weather-overlay';
import type { WeatherKey, Worn } from '@/types/game';

// three.js and the animal models only load for the 3D map.
const WorldMap3D = lazy(() => import('@/components/game/map3d/world-map-3d'));

/** If the 3D map breaks, the flat one takes over. */
class Fallback extends Component<
    { onError: () => void; children: ReactNode },
    { failed: boolean }
> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error(error, info.componentStack);
        this.props.onError();
    }

    render() {
        return this.state.failed ? null : this.props.children;
    }
}

/**
 * Hollowmere's map, flat or in 3D: the same map either way, with a button to
 * switch that this device remembers.
 */
export function GameMap({
    weather,
    ...props
}: Omit<WorldMapProps, 'switcher'> & {
    wearing: Worn[];
    /** The time of year, drawn over the map. */
    weather?: WeatherKey;
}) {
    return (
        <div className="relative">
            <GameMapInner {...props} />
            {weather && <WeatherOverlay now={weather} />}
        </div>
    );
}

function GameMapInner({
    wearing,
    ...props
}: Omit<WorldMapProps, 'switcher'> & { wearing: Worn[] }) {
    const { view, choose, available } = useMapView();

    const flat = () => {
        choose('2d');
        toast.error("The 3D map couldn't start here, so here's the flat one.");
    };

    const switcher = available ? (
        <div className="pointer-events-auto flex flex-col overflow-hidden rounded-xl border bg-card/90 shadow-lg backdrop-blur">
            <MapButton
                label={
                    view === '3d' ? 'Show the flat map' : 'Show the map in 3D'
                }
                onClick={() => choose(view === '3d' ? '2d' : '3d')}
            >
                <span className="text-xs font-black">
                    {view === '3d' ? '2D' : '3D'}
                </span>
            </MapButton>
        </div>
    ) : null;

    if (view === '2d') {
        return <WorldMap {...props} switcher={switcher} />;
    }

    return (
        <Fallback onError={flat}>
            <Suspense
                fallback={
                    <div className="flex h-[calc(100svh-15rem)] min-h-[400px] w-full items-center justify-center rounded-2xl border border-[#2e2e32] bg-[#13151a] text-sm text-muted-foreground md:h-[calc(100svh-10.5rem)] md:min-h-[520px]">
                        Loading the 3D map…
                    </div>
                }
            >
                <WorldMap3D
                    {...props}
                    wearing={wearing}
                    switcher={switcher}
                    onFail={flat}
                />
            </Suspense>
        </Fallback>
    );
}
