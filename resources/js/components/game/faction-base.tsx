import { usePage } from '@inertiajs/react';
import { Hammer } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { act, clockTime, MATERIALS } from '@/lib/game';
import { cn } from '@/lib/utils';
import { build, give, helpBuild, upgrade } from '@/routes/play';
import type {
    BuildingKindCard,
    BuildingKindValue,
    MapBuilding,
} from '@/types/game';

type Cost = { corn: number; materials: Record<string, number> };
type Store = { treasury: number; materials: Record<string, number> };

/** What the store is short of for a cost, like "20 wood". */
export function shortOf(cost: Cost, store: Store): string[] {
    const short =
        store.treasury < cost.corn
            ? [`${cost.corn - store.treasury} Corn`]
            : [];

    Object.entries(cost.materials).forEach(([material, amount]) => {
        const have = store.materials[material] ?? 0;

        if (have < amount) {
            short.push(`${amount - have} ${material}`);
        }
    });

    return short;
}

export function duration(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${minutes} minutes`;
    }

    const whole = hours === 1 ? '1 hour' : `${hours} hours`;

    return rest === 0 ? whole : `${whole} ${rest} minutes`;
}

/** A cost, with what the store can't cover yet in orange. */
export function CostList({ cost, store }: { cost: Cost; store: Store }) {
    const lines: [string, number, number][] = [
        ['Corn', cost.corn, store.treasury],
        ...Object.entries(cost.materials).map(
            ([material, amount]): [string, number, number] => [
                material,
                amount,
                store.materials[material] ?? 0,
            ],
        ),
    ];

    return (
        <span className="flex flex-wrap gap-x-2.5 gap-y-0.5 text-xs">
            {lines.map(([name, need, have]) => (
                <span
                    key={name}
                    className={cn(
                        have < need
                            ? 'font-semibold text-orange-300'
                            : 'text-muted-foreground',
                    )}
                >
                    {need} {name}
                </span>
            ))}
        </span>
    );
}

/** Three dots for a building's three levels; the one going up is hollow and pulsing. */
export function LevelPips({
    level,
    goingUp,
}: {
    level: number;
    goingUp: boolean;
}) {
    return (
        <span
            className="flex items-center gap-1"
            aria-label={`Level ${level}${goingUp ? ', going up' : ''}`}
        >
            {[1, 2, 3].map((pip) => (
                <span
                    key={pip}
                    className={cn(
                        'size-2 rounded-full border border-current',
                        pip < level || (pip === level && !goingUp)
                            ? 'bg-current'
                            : '',
                        pip === level && goingUp && 'animate-pulse',
                    )}
                />
            ))}
        </span>
    );
}

/** A building's level, what it does, and the next level for those who build. */
export function BuildingDetails({
    building,
    kind,
    store,
    canBuild,
    hasDen,
    canHelp = false,
}: {
    building: MapBuilding;
    kind: BuildingKindCard | undefined;
    store: Store | null;
    canBuild: boolean;
    hasDen: boolean;
    /** You're a member standing here who hasn't lent a hand yet. */
    canHelp?: boolean;
}) {
    const next = kind?.levels.find(
        (level) => level.level === building.level + 1,
    );
    const short = next && store ? shortOf(next, store) : [];

    return (
        <div className="flex flex-col gap-2">
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <LevelPips
                    level={building.level}
                    goingUp={building.readyAt !== null}
                />
                Level {building.level}
                {building.readyAt &&
                    ` is going up, ready at ${clockTime(building.readyAt)}`}
                {building.builders !== null &&
                    ` · ${building.builders} ${building.builders === 1 ? 'builder' : 'builders'}`}
            </p>
            {building.readyAt !== null &&
                (canHelp ? (
                    <Button
                        size="sm"
                        variant="secondary"
                        className="self-start"
                        onClick={() => act(helpBuild.url(building.id))}
                    >
                        <Hammer className="size-4" /> Help build (1 horseshoe)
                    </Button>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        {building.helped
                            ? "You're helping build it."
                            : 'Every member who helps where it stands makes it go up faster.'}
                    </p>
                ))}
            <p className="text-sm">
                {building.does ??
                    `It starts working at ${clockTime(building.readyAt ?? '')}.`}
            </p>
            {canBuild && store && next && building.readyAt === null && (
                <div className="flex flex-col gap-2 rounded-lg border p-3">
                    <p className="text-sm">
                        <span className="font-semibold">
                            Level {next.level}:
                        </span>{' '}
                        {next.does}
                    </p>
                    <CostList cost={next} store={store} />
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={
                            short.length > 0 ||
                            (building.kind !== 'den' && !hasDen)
                        }
                        onClick={() => act(upgrade.url(building.id))}
                    >
                        <Hammer className="size-4" /> Raise to level{' '}
                        {next.level} ({duration(next.minutes)})
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * What a faction can raise in a region: a Head Office in the Farmhouse,
 * and elsewhere the Den first, then the rest.
 */
export type Plot = {
    key: string;
    /** What grows there: a Mill only helps where it's Corn. */
    produces: string | null;
    /** A farm, where a Well keeps the crop watered. */
    farm: boolean;
};

/** Whether a building does any good on this land. */
function fits(kind: BuildingKindCard, plot: Plot): boolean {
    if (kind.value === 'mill') {
        return plot.produces === 'corn';
    }
    if (kind.value === 'well') {
        return plot.farm;
    }
    return true;
}

export function buildable(
    kinds: BuildingKindCard[],
    hasDen: boolean,
    plot: Plot | undefined,
): BuildingKindCard[] {
    if (plot?.key === 'farmhouse') {
        return kinds.filter((kind) => kind.value === 'head_office');
    }

    return kinds.filter(
        (kind) =>
            kind.value !== 'head_office' &&
            (hasDen ? kind.value !== 'den' : kind.value === 'den') &&
            (!plot || fits(kind, plot)),
    );
}

/** The buildings a faction may raise here, each with its cost and a Build button. */
export function BuildOptions({
    regionId,
    plot,
    kinds,
    store,
    hasDen,
}: {
    regionId: number;
    plot: Plot;
    kinds: BuildingKindCard[];
    store: Store;
    hasDen: boolean;
}) {
    const choices = buildable(kinds, hasDen, plot);

    return (
        <ul className="flex flex-col gap-2">
            {choices.map((kind) => {
                const first = kind.levels[0];
                const short = shortOf(first, store);

                return (
                    <li
                        key={kind.value}
                        className="flex flex-col gap-1.5 rounded-lg border p-3"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <span className="font-semibold">{kind.label}</span>
                            <Button
                                size="sm"
                                disabled={short.length > 0}
                                onClick={() =>
                                    act(build.url(regionId), {
                                        kind: kind.value,
                                    })
                                }
                            >
                                <Hammer className="size-4" /> Build
                            </Button>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {first.does}
                        </p>
                        <CostList cost={first} store={store} />
                    </li>
                );
            })}
            {plot.key === 'farmhouse' ? (
                <li className="text-xs text-muted-foreground">
                    Your faction rules Hollowmere once its Head Office stands.
                    While it goes up, the Farmhouse can be raided.
                </li>
            ) : (
                !hasDen && (
                    <li className="text-xs text-muted-foreground">
                        The Den comes first. After it, your faction can raise a
                        Watchtower, Drill Yard, Storehouse, Workshop, Mill,
                        Well, Coop or Silo.
                    </li>
                )
            )}
        </ul>
    );
}

type Props = {
    treasury: number;
    base: {
        canBuild: boolean;
        materials: Record<string, number>;
        /** The store of Apples, which the House spends on warrant bounties. */
        apples: number;
        /** What the faction's buildings store: Corn, and of each material. */
        capacity: { corn: number; material: number };
        memberCap: number;
        buildings: (MapBuilding & { region: { id: number; name: string } })[];
        plots: ({ id: number; name: string } & Plot)[];
    };
    kinds: BuildingKindCard[];
};

/** Your faction's base on the Faction page: its buildings, building more, and the store. */
export function FactionBase({ treasury, base, kinds }: Props) {
    const { player } = usePage().props;
    const store = { treasury, materials: base.materials };
    const hasDen = base.buildings.some((building) => building.kind === 'den');
    const [plotId, setPlotId] = useState(base.plots[0]?.id ?? 0);
    const choices = buildable(
        kinds,
        hasDen,
        base.plots.find((plot) => plot.id === plotId),
    );
    const [picked, setPicked] = useState<BuildingKindValue>(
        choices[0]?.value ?? 'den',
    );
    const kind =
        choices.find((choice) => choice.value === picked) ?? choices[0];
    const [what, setWhat] = useState('wood');
    const [amount, setAmount] = useState(10);
    const short = kind ? shortOf(kind.levels[0], store) : [];
    const have =
        what === 'corn'
            ? (player?.corn ?? 0)
            : what === 'apples'
              ? (player?.apples ?? 0)
              : (player?.materials[what] ?? 0);

    return (
        <section className="flex flex-col gap-3">
            <div>
                <h2 className="text-lg font-bold">Base</h2>
                <p className="text-sm text-muted-foreground">
                    Your faction builds on land it holds, paid from the treasury
                    and the store. The Den comes first. Raiders who take its
                    region carry off a fifth of the treasury, and every building
                    falls with its land.
                </p>
            </div>

            {base.buildings.length === 0 ? (
                <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                    No buildings yet.{' '}
                    {base.canBuild
                        ? 'Start with a Den on land you hold.'
                        : 'Your leader and council build; give materials to the store below.'}
                </p>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2">
                    {base.buildings.map((building) => (
                        <li
                            key={building.id}
                            className="flex flex-col gap-2 rounded-xl border bg-card p-4"
                        >
                            <p className="font-semibold">
                                {building.label}{' '}
                                <span className="font-normal text-muted-foreground">
                                    in {building.region.name}
                                </span>
                            </p>
                            <BuildingDetails
                                building={building}
                                kind={kinds.find(
                                    (card) => card.value === building.kind,
                                )}
                                store={store}
                                canBuild={base.canBuild}
                                hasDen={hasDen}
                            />
                        </li>
                    ))}
                </ul>
            )}

            {base.canBuild && kind && base.plots.length > 0 && (
                <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                    <h3 className="font-semibold">Raise a building</h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm">
                            Where
                            <NativeSelect
                                value={plotId}
                                onChange={(event) =>
                                    setPlotId(Number(event.target.value))
                                }
                            >
                                {base.plots.map((plot) => (
                                    <option key={plot.id} value={plot.id}>
                                        {plot.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                        <label className="grid gap-1 text-sm">
                            What
                            <NativeSelect
                                value={kind.value}
                                onChange={(event) =>
                                    setPicked(
                                        event.target.value as BuildingKindValue,
                                    )
                                }
                            >
                                {choices.map((choice) => (
                                    <option
                                        key={choice.value}
                                        value={choice.value}
                                    >
                                        {choice.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                    </div>
                    <p className="text-sm">{kind.levels[0].does}</p>
                    <CostList cost={kind.levels[0]} store={store} />
                    <Button
                        className="self-start"
                        disabled={short.length > 0}
                        onClick={() =>
                            act(build.url(plotId), { kind: kind.value })
                        }
                    >
                        <Hammer className="size-4" /> Build (
                        {duration(kind.levels[0].minutes)})
                    </Button>
                    {short.length > 0 && (
                        <p className="text-xs text-orange-300">
                            The store is short of {short.join(', ')}.
                        </p>
                    )}
                </div>
            )}

            {base.canBuild && base.plots.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Every region your faction holds has a building. Take more
                    land to build more.
                </p>
            )}

            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                <h3 className="font-semibold">The store</h3>
                <p className="text-xs text-muted-foreground">
                    Your buildings store up to{' '}
                    {base.capacity.corn.toLocaleString('en-ZA')} Corn and{' '}
                    {base.capacity.material.toLocaleString('en-ZA')} of each
                    material; a Den or a Storehouse holds the most. Raiders who
                    take one of your buildings carry off what it holds.
                </p>
                <ul className="flex flex-wrap gap-2 text-sm">
                    <li className="rounded-full border px-3 py-1">
                        <span className="font-semibold">
                            {treasury.toLocaleString('en-ZA')}
                        </span>
                        <span className="text-muted-foreground">
                            /{base.capacity.corn.toLocaleString('en-ZA')}
                        </span>{' '}
                        Corn
                    </li>
                    <li
                        className="rounded-full border px-3 py-1"
                        title="Whoever rules the Farmhouse spends its Apples on warrant bounties"
                    >
                        <span aria-hidden>🍎</span>{' '}
                        <span className="font-semibold">
                            {base.apples.toLocaleString('en-ZA')}
                        </span>{' '}
                        Apples
                    </li>
                    {MATERIALS.map(({ name, from }) => (
                        <li
                            key={name}
                            className="rounded-full border px-3 py-1"
                            title={`Gathered in ${from}`}
                        >
                            <span className="font-semibold">
                                {base.materials[name] ?? 0}
                            </span>
                            <span className="text-muted-foreground">
                                /{base.capacity.material}
                            </span>{' '}
                            {name}
                        </li>
                    ))}
                </ul>
                <p className="text-xs text-muted-foreground">
                    {MATERIALS.map(
                        ({ name, from }) =>
                            `${name[0].toUpperCase()}${name.slice(1)} from ${from}`,
                    ).join(' · ')}
                    .
                </p>
                <div className="flex flex-wrap items-end gap-2">
                    <label className="grid gap-1 text-sm">
                        Give
                        <NativeSelect
                            value={what}
                            onChange={(event) => setWhat(event.target.value)}
                        >
                            <option value="corn">Corn</option>
                            <option value="apples">Apples</option>
                            {MATERIALS.map(({ name }) => (
                                <option key={name} value={name}>
                                    {name[0].toUpperCase() + name.slice(1)}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Amount
                        <Input
                            type="number"
                            min={1}
                            max={Math.max(1, have)}
                            value={amount}
                            onChange={(event) =>
                                setAmount(Number(event.target.value))
                            }
                            className="w-24"
                        />
                    </label>
                    <Button
                        variant="secondary"
                        disabled={have < 1 || amount < 1}
                        onClick={() => act(give.url(), { what, amount })}
                    >
                        Give
                    </Button>
                    <span className="pb-2 text-xs text-muted-foreground">
                        You have {have}.
                    </span>
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                Room for {base.memberCap} members. A bigger Den makes room for
                more.
            </p>
        </section>
    );
}
