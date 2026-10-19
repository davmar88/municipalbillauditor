import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Home as HomeIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { WhyNot } from '@/components/game/why-not';
import { act, capitalise, clockTime } from '@/lib/game';
import type { Point } from '@/lib/hollowmere';
import {
    build,
    demolish,
    enter,
    help,
    leave,
    skin,
    style,
    upgrade,
} from '@/routes/play/home';
import type { HomeInfo, MapHome, MapMe } from '@/types/game';

function cost(level: HomeInfo['levels'][number]): string {
    return [
        ...(level.corn > 0 ? [`${level.corn} Corn`] : []),
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
    const canPay = (level: HomeInfo['levels'][number]) =>
        corn >= level.corn &&
        Object.entries(level.materials).every(
            ([name, n]) => (materials[name] ?? 0) >= n,
        );
    const affordable = next !== undefined && canPay(next);

    return (
        <section className="flex flex-col gap-2 rounded-2xl border bg-card p-4 text-sm">
            <h2 className="flex items-center gap-2 text-base font-bold">
                <HomeIcon className="size-4" aria-hidden /> Your home
            </h2>

            {home === null ? (
                <>
                    <p className="text-muted-foreground">
                        Set up camp right where you stand: pitch a Tent, quick
                        and cheap, or build a Hut with walls burners have to get
                        past. Either hides you from bounty hunters, and a Tent
                        can be raised to a Hut later. Not in the Farmhouse, the
                        Paddock, Irongate or Ironfield.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {[0, 1].map((level) => {
                            const option = info.levels.find(
                                (l) => l.level === level,
                            );

                            return option ? (
                                <>
                                    <Button
                                        key={level}
                                        size="sm"
                                        variant={
                                            level === 0
                                                ? 'secondary'
                                                : 'default'
                                        }
                                        disabled={
                                            !canPay(option) || me.walk !== null
                                        }
                                        onClick={() =>
                                            act(build.url(), { level })
                                        }
                                        title={`${cost(option)} · ${option.minutes} minutes`}
                                    >
                                        {level === 0
                                            ? '⛺ Pitch a Tent'
                                            : '🛖 Build a Hut'}{' '}
                                        here
                                    </Button>
                                    <WhyNot
                                        reason={
                                            me.walk !== null
                                                ? 'Stop walking first: you set up camp where you stand.'
                                                : !canPay(option)
                                                  ? `A ${option.name} takes ${cost(option)}. You have ${corn} Corn and ${Object.entries(
                                                        option.materials,
                                                    )
                                                        .map(
                                                            ([name]) =>
                                                                `${materials[name] ?? 0} ${name}`,
                                                        )
                                                        .join(', ')}.`
                                                  : null
                                        }
                                    />
                                </>
                            ) : null;
                        })}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {info.levels
                            .filter((l) => l.level <= 1)
                            .map(
                                (l) =>
                                    `A ${l.name}: ${cost(l)}, ${l.minutes} minutes.`,
                            )
                            .join(' ')}
                    </p>
                </>
            ) : (
                <>
                    <p>
                        Your{' '}
                        <span className="font-bold">
                            {home.level > 0 ? `level ${home.level} ` : ''}
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
                            <>
                                <Button
                                    size="sm"
                                    disabled={
                                        home.level <= 1 && home.readyAt !== null
                                    }
                                    onClick={() => act(enter.url())}
                                >
                                    Go inside and hide
                                </Button>
                                <WhyNot
                                    reason={
                                        home.level <= 1 &&
                                        home.readyAt !== null &&
                                        `Your ${home.name} is still going up. You can go in once it stands, at ${clockTime(home.readyAt)}.`
                                    }
                                />
                            </>
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
                            <>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        !affordable || home.readyAt !== null
                                    }
                                    onClick={() => act(upgrade.url())}
                                    title={cost(next)}
                                >
                                    Raise to a {next.name}
                                </Button>
                                <WhyNot
                                    reason={
                                        home.readyAt !== null
                                            ? `Your ${home.name} is still going up. Raise it again once it stands.`
                                            : !affordable
                                              ? `A ${next.name} takes ${cost(next)}. Gather what's missing, then come back to your door.`
                                              : null
                                    }
                                />
                            </>
                        )}
                    </div>
                    {next && (
                        <p className="text-xs text-muted-foreground">
                            A {next.name} takes {cost(next)} and {next.minutes}{' '}
                            minutes, built at your door, and homes keep growing
                            past it. Faction mates can lend a hand while a level
                            goes up, and a Builder's Mortar from the Market
                            raises it at once. Each level makes you harder to
                            arrest inside and your home harder to burn.
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
                    <BreakDown
                        atDoor={atDoor && !info.inside}
                        name={home.name}
                        back={
                            info.levels.find((l) => l.level === home.level)
                                ?.materials ?? {}
                        }
                    />
                    {home.level > 0 && (
                        <label className="flex flex-wrap items-center gap-2 text-xs">
                            <span className="font-semibold">Look</span>
                            <select
                                value={home.style ?? ''}
                                onChange={(event) =>
                                    act(style.url(), {
                                        style: event.target.value || null,
                                    })
                                }
                                className="rounded-md border bg-background px-2 py-1"
                            >
                                <option value="">Village house</option>
                                {Object.entries(info.styles).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            <span className="text-muted-foreground">
                                How it looks on the 3D map. Its level still
                                decides how strong it is.
                            </span>
                        </label>
                    )}
                </>
            )}
            {homes.some((h) => h.helpable) && (
                <div className="flex flex-col gap-1 border-t pt-2">
                    <p className="text-xs font-semibold">
                        Faction mates' homes going up
                    </p>
                    {homes
                        .filter((h) => h.helpable)
                        .map((h) => (
                            <div
                                key={h.id}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <span className="min-w-0 flex-1 truncate">
                                    {h.owner.name}'s {h.name}
                                </span>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => onWalk([h.x, h.y])}
                                >
                                    Walk there
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={
                                        me.walk !== null ||
                                        Math.hypot(h.x - me.x, h.y - me.y) >
                                            info.door
                                    }
                                    onClick={() => act(help.url(h.id))}
                                >
                                    Lend a hand
                                </Button>
                                <WhyNot
                                    reason={
                                        me.walk !== null
                                            ? 'Stop walking first.'
                                            : Math.hypot(
                                                    h.x - me.x,
                                                    h.y - me.y,
                                                ) > info.door
                                              ? `Walk to ${h.owner.name}'s door first: you help where you stand.`
                                              : null
                                    }
                                />
                            </div>
                        ))}
                </div>
            )}
        </section>
    );
}

/** Break your home down at its door: asks once more first, and gives back half its top level's materials. */
function BreakDown({
    atDoor,
    name,
    back,
}: {
    atDoor: boolean;
    name: string;
    back: Record<string, number>;
}) {
    const [sure, setSure] = useState(false);
    const half = Object.entries(back)
        .map(([material, n]) => `${Math.floor(n / 2)} ${material}`)
        .join(', ');

    if (!atDoor) {
        return null;
    }

    return sure ? (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-2 text-xs">
            <span>
                Break down your {name}? You get back {half || 'nothing'}, and
                can set up camp somewhere else.
            </span>
            <Button
                size="sm"
                variant="destructive"
                onClick={() => {
                    act(demolish.url());
                    setSure(false);
                }}
            >
                Yes, break it down
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setSure(false)}>
                Keep it
            </Button>
        </div>
    ) : (
        <Button
            size="sm"
            variant="ghost"
            className="self-start text-red-300"
            onClick={() => setSure(true)}
        >
            Break it down
        </Button>
    );
}
