import { Head, Link } from '@inertiajs/react';
import { Crosshair, Footprints, Gavel, Siren } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { act, clockTime, fromNow, speciesEmoji } from '@/lib/game';
import { openChat } from '@/lib/pulse';
import { play } from '@/routes';
import { faction } from '@/routes/play';
import { arrest, issue, raise } from '@/routes/play/warrant';
import type { MessageAnimal } from '@/types/game';

type Warrant = {
    id: number;
    animal: MessageAnimal;
    /** In Apples, which the hunter who catches them collects. */
    bounty: number;
    issuedBy: string | null;
    lastSeen: string;
    inPound: boolean;
    escapedUntil: string | null;
    mine: boolean;
    here: boolean;
};

type Props = {
    warrants: Warrant[];
    caught: { id: number; text: string; at: string | null }[];
    house: {
        name: string;
        /** The House's store of Apples, for bounties. */
        apples: number;
        suspects: {
            animal: MessageAnimal;
            deed: string;
            at: string;
            warranted: boolean;
        }[];
    } | null;
    houseName: string | null;
    rules: {
        minBounty: number;
        maxBounty: number;
        horseshoes: number;
        chance: number;
        dogChance: number;
        escapeMinutes: number;
    };
};

/**
 * The Wanted board: the House's warrants and their bounties, where each
 * outlaw was last seen, and for the House, the suspects its informants name.
 */
export default function WantedPage({
    warrants,
    caught,
    house,
    houseName,
    rules,
}: Props) {
    return (
        <>
            <Head title="Wanted" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <GameBar />
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Crosshair
                            className="size-6 text-orange-400"
                            aria-hidden
                        />{' '}
                        Wanted
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {houseName
                            ? `${houseName} puts out warrants`
                            : 'Whoever rules the Farmhouse puts out warrants'}{' '}
                        for animals its informants catch breaking the law. Walk
                        to where an outlaw stands and try an arrest for{' '}
                        {rules.horseshoes} horseshoes: it works {rules.chance}%
                        of the time, {rules.dogChance}% for dogs. A catch sends
                        them to the pound and pays you every Apple on the
                        bounty; a miss lets them slip away for{' '}
                        {rules.escapeMinutes} minutes. Anyone can add Apples of
                        their own to a bounty.
                    </p>
                </header>

                {warrants.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                        No warrants are out. The valley is quiet, for now.
                    </p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {warrants.map((warrant) => (
                            <WarrantCard
                                key={warrant.id}
                                warrant={warrant}
                                horseshoes={rules.horseshoes}
                            />
                        ))}
                    </ul>
                )}

                {house && <Suspects house={house} rules={rules} />}

                {caught.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-lg font-bold">Caught lately</h2>
                        <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                            {caught.map((item) => (
                                <li key={item.id}>
                                    {item.at && `${clockTime(item.at)} · `}
                                    {item.text}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function WarrantCard({
    warrant,
    horseshoes,
}: {
    warrant: Warrant;
    horseshoes: number;
}) {
    const [amount, setAmount] = useState(5);

    return (
        <li className="flex flex-col gap-3 rounded-xl border border-orange-500/40 bg-orange-500/5 p-4">
            <div className="flex items-start gap-3">
                <span
                    className="flex size-12 shrink-0 items-center justify-center rounded-full border-2 bg-background text-2xl"
                    style={{ borderColor: warrant.animal.colour ?? undefined }}
                    aria-hidden
                >
                    {speciesEmoji(warrant.animal.species)}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-[11px] font-bold tracking-widest text-orange-300 uppercase">
                        Wanted
                    </p>
                    <p className="truncate text-lg font-extrabold">
                        {warrant.animal.name}
                    </p>
                    {warrant.animal.faction && (
                        <p
                            className="text-xs"
                            style={{
                                color: warrant.animal.colour ?? undefined,
                            }}
                        >
                            {warrant.animal.faction}
                        </p>
                    )}
                </div>
                <div className="text-right">
                    <p className="text-2xl font-extrabold">
                        <span aria-hidden>🍎</span>{' '}
                        {warrant.bounty.toLocaleString('en-ZA')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        bounty in Apples
                    </p>
                </div>
            </div>

            <p className="text-sm">
                {warrant.inPound ? (
                    <span className="text-muted-foreground">
                        In the pound right now.
                    </span>
                ) : (
                    <>
                        Last seen in{' '}
                        <span className="font-semibold">
                            {warrant.lastSeen}
                        </span>
                        {warrant.here && ', right where you are'}.
                        {warrant.escapedUntil &&
                            ` Slipped away: back on the trail ${fromNow(warrant.escapedUntil)}.`}
                    </>
                )}
                {warrant.issuedBy && (
                    <span className="text-muted-foreground">
                        {' '}
                        Warrant from {warrant.issuedBy}.
                    </span>
                )}
            </p>

            {warrant.mine ? (
                <p className="flex items-center gap-2 text-sm font-semibold text-orange-300">
                    <Siren className="size-4" aria-hidden /> That's you. Keep
                    moving.
                </p>
            ) : (
                <div className="flex flex-wrap items-center gap-2">
                    {warrant.here ? (
                        <Button
                            disabled={
                                warrant.inPound || warrant.escapedUntil !== null
                            }
                            onClick={() => act(arrest.url(warrant.id))}
                        >
                            <Gavel className="size-4" /> Arrest ({horseshoes}{' '}
                            horseshoes)
                        </Button>
                    ) : (
                        <Button variant="secondary" asChild>
                            <Link href={play()}>
                                <Footprints className="size-4" /> Go to the map
                            </Link>
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => openChat(warrant.animal.id)}
                    >
                        Write to them
                    </Button>
                    <div className="ml-auto flex items-center gap-1.5">
                        <Input
                            type="number"
                            min={1}
                            value={amount}
                            onChange={(event) =>
                                setAmount(Number(event.target.value))
                            }
                            className="h-8 w-20"
                            aria-label="Apples to add to the bounty"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                act(raise.url(warrant.id), { amount })
                            }
                        >
                            Add Apples
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}

function Suspects({
    house,
    rules,
}: {
    house: NonNullable<Props['house']>;
    rules: Props['rules'];
}) {
    const [bounties, setBounties] = useState<Record<number, number>>({});

    return (
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div>
                <h2 className="text-lg font-bold">The House's informants</h2>
                <p className="text-sm text-muted-foreground">
                    Animals seen breaking the law in the last three days. A
                    warrant's bounty comes out of the House's store of Apples,
                    which holds {house.apples.toLocaleString('en-ZA')}{' '}
                    {house.apples === 1 ? 'Apple' : 'Apples'}. Members give
                    Apples to it on the{' '}
                    <Link
                        href={faction()}
                        className="underline underline-offset-2"
                    >
                        Faction page
                    </Link>
                    .
                </p>
            </div>
            {house.suspects.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nobody has been seen breaking the law lately.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {house.suspects.map((suspect) => {
                        const bounty =
                            bounties[suspect.animal.id] ??
                            Math.max(
                                rules.minBounty,
                                Math.min(20, house.apples),
                            );

                        return (
                            <li
                                key={suspect.animal.id}
                                className="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm"
                            >
                                <span aria-hidden>
                                    {speciesEmoji(suspect.animal.species)}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="font-semibold">
                                        {suspect.animal.name}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        {suspect.deed},{' '}
                                        {fromNow(suspect.at) === 'now'
                                            ? 'just now'
                                            : clockTime(suspect.at)}
                                    </span>
                                </span>
                                {suspect.warranted ? (
                                    <span className="text-xs font-semibold text-orange-300">
                                        Warrant out
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1.5">
                                        <Input
                                            type="number"
                                            min={rules.minBounty}
                                            max={rules.maxBounty}
                                            value={bounty}
                                            onChange={(event) =>
                                                setBounties({
                                                    ...bounties,
                                                    [suspect.animal.id]: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                            className="h-8 w-24"
                                            aria-label={`Bounty in Apples on ${suspect.animal.name}`}
                                        />
                                        <span aria-hidden>🍎</span>
                                        <Button
                                            size="sm"
                                            disabled={
                                                bounty < rules.minBounty ||
                                                bounty > house.apples
                                            }
                                            onClick={() =>
                                                act(issue.url(), {
                                                    player_id:
                                                        suspect.animal.id,
                                                    bounty,
                                                })
                                            }
                                        >
                                            <Crosshair className="size-4" /> Put
                                            out a warrant
                                        </Button>
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
