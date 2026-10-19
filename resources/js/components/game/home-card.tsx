import { usePage } from '@inertiajs/react';
import { Home as HomeIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { act, capitalise, clockTime } from '@/lib/game';
import type { Point } from '@/lib/hollowmere';
import { build, enter, leave, skin, upgrade } from '@/routes/play/home';
import type { HomeInfo, MapHome, MapMe } from '@/types/game';

function cost(level: HomeInfo['levels'][number]): string {
    return [
        `${level.corn} Corn`,
        ...Object.entries(level.materials).map(([name, n]) => `${n} ${name}`),
    ].join(', ');
}

/**
 * Your own home, under the map: build a Hut where you stand, walk back to
 * it, hide inside, raise it to a Cottage and a Fort, and dress it in a skin.
 */
export function HomeCard({
    info,
    homes,
    me,
    onWalk,
}: {
    info: HomeInfo;
    homes: MapHome[];
    me: MapMe;
    onWalk: (point: Point) => void;
}) {
    const { player } = usePage().props;
    const home = homes.find((h) => h.mine) ?? null;
    const atDoor =
        home !== null &&
        me.walk === null &&
        Math.hypot(home.x - me.x, home.y - me.y) <= info.door;
    const next = info.levels.find((l) => l.level === (home?.level ?? 0) + 1);
    const corn = player?.corn ?? 0;
    const materials = (player?.materials ?? {}) as Record<string, number>;
    const affordable =
        next !== undefined &&
        corn >= next.corn &&
        Object.entries(next.materials).every(
            ([name, n]) => (materials[name] ?? 0) >= n,
        );

    return (
        <section className="flex flex-col gap-2 rounded-2xl border bg-card p-4 text-sm">
            <h2 className="flex items-center gap-2 text-base font-bold">
                <HomeIcon className="size-4" aria-hidden /> Your home
            </h2>

            {home === null ? (
                <>
                    <p className="text-muted-foreground">
                        Build a Hut right where you stand: somewhere to hide
                        from bounty hunters, and walls burners have to get past.
                        Not in the Farmhouse, the Paddock or Irongate.
                    </p>
                    {next && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                disabled={!affordable || me.walk !== null}
                                onClick={() => act(build.url())}
                            >
                                Build a Hut here
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                {cost(next)} · {next.minutes} minutes
                            </span>
                        </div>
                    )}
                </>
            ) : (
                <>
                    <p>
                        Your{' '}
                        <span className="font-bold">
                            {home.name}
                            {home.skin && ` in ${home.skin.name}`}
                        </span>
                        {home.readyAt
                            ? ` is going up. It stands at ${clockTime(home.readyAt)}.`
                            : '.'}{' '}
                        {info.inside
                            ? "You're inside, hidden from the map."
                            : atDoor
                              ? "You're at the door."
                              : ''}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {!atDoor && !info.inside && (
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => onWalk([home.x, home.y])}
                            >
                                Walk home
                            </Button>
                        )}
                        {atDoor && !info.inside && (
                            <Button
                                size="sm"
                                disabled={
                                    home.level === 1 && home.readyAt !== null
                                }
                                onClick={() => act(enter.url())}
                            >
                                Go inside and hide
                            </Button>
                        )}
                        {info.inside && (
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => act(leave.url())}
                            >
                                Step outside
                            </Button>
                        )}
                        {next && atDoor && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={!affordable || home.readyAt !== null}
                                onClick={() => act(upgrade.url())}
                                title={cost(next)}
                            >
                                Raise to a {next.name}
                            </Button>
                        )}
                    </div>
                    {next && (
                        <p className="text-xs text-muted-foreground">
                            A {next.name} takes {cost(next)} and {next.minutes}{' '}
                            minutes, built at your door. Each level makes you
                            harder to arrest inside and your home harder to
                            burn.
                        </p>
                    )}
                    <label className="flex flex-wrap items-center gap-2 text-xs">
                        <span className="font-semibold">Skin</span>
                        <select
                            value={
                                info.skins.find(
                                    (s) => s.name === home.skin?.name,
                                )?.id ?? ''
                            }
                            onChange={(event) =>
                                act(skin.url(), {
                                    item: event.target.value
                                        ? Number(event.target.value)
                                        : null,
                                })
                            }
                            className="rounded-md border bg-background px-2 py-1"
                        >
                            <option value="">Plain boards</option>
                            {info.skins.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {capitalise(s.name)}
                                </option>
                            ))}
                        </select>
                        {info.skins.length === 0 && (
                            <span className="text-muted-foreground">
                                Make or buy a home skin in the Market.
                            </span>
                        )}
                    </label>
                </>
            )}
        </section>
    );
}
