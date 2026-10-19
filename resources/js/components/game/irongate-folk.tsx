import { Link, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { besidePoint, useEscape } from '@/components/game/animal-menu';
import type { Warrant } from '@/components/game/region-panel';
import { Button } from '@/components/ui/button';
import { WhyNot } from '@/components/game/why-not';
import { act, capitalise } from '@/lib/game';
import type { Point } from '@/lib/hollowmere';
import { chat } from '@/routes/play';
import { fence, meal, smith, tip } from '@/routes/play/irongate';
import type { Folk, IrongateInfo, MapRegion } from '@/types/game';

/** How each of Irongate's people looks on the map and on their card. */
export const FOLK_FACE: Record<string, string> = {
    brannock: '💂',
    vetch: '👩',
    wick: '🕵️',
    tamsin: '👵',
    fallow: '🧔',
    bram: '🧑‍🏭',
    nell: '👩‍🍳',
    cobb: '💂‍♂️',
    rook: '🧙‍♀️',
};

export const folkFace = (key: string) => FOLK_FACE[key] ?? '🧑';

/**
 * What one of Irongate's people does for you: the Captain counts Sunday's
 * men, the fence buys materials, the informer sells word of an outlaw, the
 * Rookery's keeper opens the board. The server checks where you stand.
 */
export function FolkActions({
    folk,
    irongate,
    warrants,
    regions,
    here,
}: {
    folk: Folk;
    irongate: IrongateInfo;
    warrants: Warrant[];
    regions: MapRegion[];
    here: number | null;
}) {
    const { player } = usePage().props;
    const standing = regions.find((region) => region.id === here) ?? null;
    const inTown = standing?.ring === 'city';
    const inDistrict = standing?.key === folk.district;
    const loneWolf = !player?.faction;

    if (folk.key === 'brannock') {
        return (
            <p>
                <span className="font-bold text-[#f5a27a]">
                    {irongate.muster} men
                </span>{' '}
                muster for Sunday's Gate Night at 19:00. Hold the gate with
                horseshoes from the map on Sunday.
            </p>
        );
    }

    if (folk.key === 'vetch') {
        return (
            <Fence
                irongate={irongate}
                materials={(player?.materials ?? {}) as Record<string, number>}
                ready={inDistrict && loneWolf}
                why={
                    !loneWolf
                        ? "She won't deal with faction animals."
                        : !inDistrict
                          ? 'Walk to the Iron Yards to deal with her.'
                          : null
                }
            />
        );
    }

    if (folk.key === 'wick') {
        return (
            <Tips
                irongate={irongate}
                warrants={warrants.filter((w) => w.playerId !== player?.id)}
                ready={inTown}
            />
        );
    }

    if (folk.key === 'bram') {
        return (
            <div className="flex flex-col gap-2">
                <p>
                    {irongate.smith.stone} stone and {irongate.smith.wood} wood
                    for a piece of iron, up to {irongate.smith.daily} times a
                    day.
                </p>
                <Button
                    size="sm"
                    className="self-start"
                    disabled={!inDistrict}
                    onClick={() => act(smith.url())}
                >
                    {inDistrict ? 'Make iron' : 'Walk to Smithy Row first'}
                </Button>
            </div>
        );
    }

    if (folk.key === 'nell') {
        return (
            <div className="flex flex-col gap-2">
                <p>
                    A hot meal for {irongate.meal} Corn gives you a horseshoe,
                    once a day.
                </p>
                <Button
                    size="sm"
                    className="self-start"
                    disabled={!inDistrict}
                    onClick={() => act(meal.url())}
                >
                    {inDistrict
                        ? 'Eat a hot meal'
                        : 'Walk to the Black Dog first'}
                </Button>
            </div>
        );
    }

    if (folk.key === 'cobb') {
        return (
            <p>
                He's drilling{' '}
                <span className="font-bold text-[#f5a27a]">
                    {irongate.muster} men
                </span>{' '}
                for Sunday's Gate Night.
            </p>
        );
    }

    if (folk.key === 'rook') {
        return (
            <p className="text-muted-foreground">
                Her fortunes are coming. For now she only watches.
            </p>
        );
    }

    if (folk.key === 'tamsin') {
        return loneWolf ? (
            <Link
                href={chat.url({ query: { channel: 'rookery' } })}
                className="self-start rounded-lg border px-3 py-1.5 font-semibold hover:bg-accent"
            >
                Open the Rookery board
            </Link>
        ) : (
            <p className="text-muted-foreground">
                The Rookery board is for lone wolves. Faction animals talk in
                their own channels.
            </p>
        );
    }

    return (
        <p className="text-muted-foreground">
            Syndicates of lone wolves are coming to the Rookery.
        </p>
    );
}

function Fence({
    irongate,
    materials,
    ready,
    why,
}: {
    irongate: IrongateInfo;
    materials: Record<string, number>;
    ready: boolean;
    why: string | null;
}) {
    const owned = Object.entries(materials).filter(([, n]) => n > 0);
    const [picked, setMaterial] = useState(owned[0]?.[0] ?? '');
    const [amount, setAmount] = useState(5);
    // After a sale empties the picked material, the next one you have takes its place.
    const material = owned.some(([name]) => name === picked)
        ? picked
        : (owned[0]?.[0] ?? '');
    const price = irongate.fence.rare.includes(material)
        ? irongate.fence.rarePrice
        : irongate.fence.price;

    return (
        <div className="flex flex-col gap-2">
            <p>
                {irongate.fence.price} Corn each for materials,{' '}
                {irongate.fence.rarePrice} for the farms' rare crops. She'll pay
                you {irongate.fence.left} more Corn today.
            </p>
            {why ? (
                <p className="text-muted-foreground">{why}</p>
            ) : owned.length === 0 ? (
                <p className="text-muted-foreground">
                    You have nothing to sell her.
                </p>
            ) : (
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        value={material}
                        onChange={(event) => setMaterial(event.target.value)}
                        className="rounded-md border bg-background px-2 py-1"
                        aria-label="Material to sell"
                    >
                        {owned.map(([name, n]) => (
                            <option key={name} value={name}>
                                {capitalise(name)} ({n})
                            </option>
                        ))}
                    </select>
                    <input
                        type="number"
                        min={1}
                        value={amount}
                        onChange={(event) =>
                            setAmount(
                                Math.min(
                                    1000,
                                    Math.max(
                                        1,
                                        Math.floor(
                                            Number(event.target.value) || 1,
                                        ),
                                    ),
                                ),
                            )
                        }
                        className="w-16 rounded-md border bg-background px-2 py-1"
                        aria-label="How many"
                    />
                    <Button
                        size="sm"
                        disabled={!ready || irongate.fence.left < price}
                        onClick={() => act(fence.url(), { material, amount })}
                    >
                        Sell for {Math.min(amount * price, irongate.fence.left)}{' '}
                        Corn
                    </Button>
                    <WhyNot
                        reason={
                            !ready
                                ? 'Walk to the Iron Yards first: Ma Vetch only deals face to face.'
                                : irongate.fence.left < price
                                  ? "Ma Vetch has bought all she'll pay for today. Come back tomorrow."
                                  : null
                        }
                    />
                </div>
            )}
        </div>
    );
}

function Tips({
    irongate,
    warrants,
    ready,
}: {
    irongate: IrongateInfo;
    warrants: Warrant[];
    ready: boolean;
}) {
    if (warrants.length === 0) {
        return (
            <p className="text-muted-foreground">
                Nobody's wanted right now. Nothing to tell.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            <p>
                {irongate.tip} Corn and he tells you which district an outlaw
                hides in.{' '}
                {!ready &&
                    'He only talks in Irongate: walk into the town first.'}
            </p>
            <ul className="flex flex-col gap-1">
                {warrants.map((warrant) => (
                    <li key={warrant.id} className="flex items-center gap-2">
                        <span className="min-w-0 flex-1 truncate font-semibold">
                            🎯 {warrant.name}
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={!ready}
                            onClick={() => act(tip.url(warrant.id))}
                        >
                            Ask Wick
                        </Button>
                        <WhyNot
                            reason={
                                !ready &&
                                'Walk to the Iron Yards first: Wick only whispers face to face.'
                            }
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Irongate's people standing in a district, for its region card. */
export function DistrictFolk({
    region,
    ...props
}: {
    region: MapRegion;
    irongate: IrongateInfo;
    warrants: Warrant[];
    regions: MapRegion[];
    here: number | null;
}) {
    const folk = props.irongate.folk.filter(
        (person) => person.district === region.key,
    );

    return (
        <div className="flex flex-col gap-3">
            <p className="text-muted-foreground">
                Irongate, the humans' town. No faction can take it, and an
                outlaw lying low here is hidden from anyone outside this
                district.
            </p>
            {folk.map((person) => (
                <div
                    key={person.key}
                    className="flex flex-col gap-2 rounded-lg border p-3"
                >
                    <p className="flex items-center gap-2">
                        <span className="text-xl" aria-hidden>
                            {folkFace(person.key)}
                        </span>
                        <span>
                            <span className="block font-bold">
                                {person.name}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {person.role}
                            </span>
                        </span>
                    </p>
                    <p className="italic">"{person.says}"</p>
                    <FolkActions folk={person} {...props} />
                </div>
            ))}
        </div>
    );
}

/** The card for one of Irongate's people, opened by tapping them on the map. */
export function FolkCard({
    folk,
    at,
    size,
    onClose,
    ...props
}: {
    folk: Folk;
    at: Point;
    size: { width: number; height: number };
    onClose: () => void;
    irongate: IrongateInfo;
    warrants: Warrant[];
    regions: MapRegion[];
    here: number | null;
}) {
    useEscape(onClose);

    return (
        <div
            role="dialog"
            aria-label={folk.name}
            className="absolute z-20 flex flex-col gap-2 overflow-y-auto rounded-xl border bg-card/95 p-3 text-sm shadow-xl backdrop-blur"
            style={besidePoint(at, size, 272)}
            onPointerDown={(event) => event.stopPropagation()}
        >
            <div className="flex items-start gap-2">
                <span className="text-2xl" aria-hidden>
                    {folkFace(folk.key)}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block font-bold">{folk.name}</span>
                    <span className="text-xs text-muted-foreground">
                        {folk.role}
                    </span>
                </span>
                <button
                    type="button"
                    aria-label="Close"
                    onClick={onClose}
                    className="rounded-md p-1 text-muted-foreground hover:bg-accent"
                >
                    <X className="size-4" />
                </button>
            </div>
            <p className="italic">"{folk.says}"</p>
            <FolkActions folk={folk} {...props} />
        </div>
    );
}
