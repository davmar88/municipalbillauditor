import { Head, Link } from '@inertiajs/react';
import { Crosshair, Footprints, Gavel, Siren } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { ProfileLink } from '@/components/game/profile-link';
import { Button } from '@/components/ui/button';
import { WhyNot } from '@/components/game/why-not';
import { Input } from '@/components/ui/input';
import { act, clockTime, fromNow, speciesEmoji } from '@/lib/game';
import { openChat } from '@/lib/pulse';
import { play } from '@/routes';
import { faction } from '@/routes/play';
import { catchMethod as catchRoute } from '@/routes/play/crime';
import { arrest, issue, raise } from '@/routes/play/warrant';
import type { MessageAnimal } from '@/types/game';

type Warrant = {
    id: number;
    animal: MessageAnimal;
    /** In Apples. */
    bounty: number;
    /** What the hunter who catches them collects: the bounty less the game's share. */
    payout: number;
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
    /** Who wronged you lately: catch them, or put a bounty on them. */
    againstMe: {
        crimeId: number;
        animal: MessageAnimal;
        deed: string;
        at: string;
        /** Until when you can still catch them for it. */
        until: string;
        lastSeen: string;
        here: boolean;
        inPound: boolean;
        escapedUntil: string | null;
        warranted: boolean;
    }[];
    /** Your own Apples, for a bounty of your own. */
    apples: number;
    catch: { horseshoes: number; chance: number; hours: number };
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
    rules,
    againstMe,
    apples,
    catch: catchRules,
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
                        Nobody goes to the pound by chance. The animal wronged
                        catches the criminal, or puts a warrant out with a
                        bounty from their own Apples; any faction's leader and
                        council can too, from their faction's store. Walk to
                        where an outlaw stands and try an arrest for{' '}
                        {rules.horseshoes} horseshoes: it works {rules.chance}%
                        of the time, {rules.dogChance}% for dogs. A catch sends
                        them to the pound and pays you the bounty, less the
                        game's share; a miss lets them slip away for{' '}
                        {rules.escapeMinutes} minutes. Anyone can add Apples of
                        their own to a bounty.
                    </p>
                </header>

                {againstMe.length > 0 && (
                    <AgainstMe
                        crimes={againstMe}
                        apples={apples}
                        rules={rules}
                        catchRules={catchRules}
                    />
                )}

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
                        {warrant.mine ? (
                            warrant.animal.name
                        ) : (
                            <ProfileLink animal={warrant.animal} />
                        )}
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
                        bounty in Apples · pays{' '}
                        {warrant.payout.toLocaleString('en-ZA')}
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
                <h2 className="text-lg font-bold">What the informants say</h2>
                <p className="text-sm text-muted-foreground">
                    Animals seen breaking the law in the last three days. A
                    warrant's bounty comes out of {house.name}'s store of
                    Apples, which holds {house.apples.toLocaleString('en-ZA')}{' '}
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
                                    <ProfileLink
                                        animal={suspect.animal}
                                        className="font-semibold"
                                    />{' '}
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
                                        <WhyNot
                                            reason={
                                                bounty < rules.minBounty
                                                    ? `A bounty is at least ${rules.minBounty} Apples.`
                                                    : bounty > house.apples
                                                      ? `${house.name} has only ${house.apples} Apples to put up.`
                                                      : null
                                            }
                                        />
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

/**
 * Who wronged you lately. Nobody goes to the pound by chance: you catch
 * them yourself where they stand, or put a bounty on them for others to.
 */
function AgainstMe({
    crimes,
    apples,
    rules,
    catchRules,
}: {
    crimes: Props['againstMe'];
    apples: number;
    rules: Props['rules'];
    catchRules: Props['catch'];
}) {
    return (
        <section className="flex flex-col gap-3 rounded-xl border border-red-500/40 bg-red-500/5 p-4">
            <div>
                <h2 className="flex items-center gap-2 text-lg font-bold">
                    <Siren className="size-5 text-red-400" aria-hidden /> Crimes
                    against you
                </h2>
                <p className="text-sm text-muted-foreground">
                    Walk to where they stand and catch them for{' '}
                    {catchRules.horseshoes} horseshoes: it works{' '}
                    {catchRules.chance}% of the time, less if they hide in a
                    strong home. A catch sends them to the pound and they pay
                    back what they took. You have {catchRules.hours} hours. Or
                    put a bounty on them from your own Apples and let the bounty
                    hunters do it.
                </p>
            </div>
            <ul className="flex flex-col gap-2">
                {crimes.map((crime) => (
                    <AgainstMeRow
                        key={crime.crimeId}
                        crime={crime}
                        apples={apples}
                        rules={rules}
                    />
                ))}
            </ul>
        </section>
    );
}

function AgainstMeRow({
    crime,
    apples,
    rules,
}: {
    crime: Props['againstMe'][number];
    apples: number;
    rules: Props['rules'];
}) {
    const [bounty, setBounty] = useState(
        Math.max(rules.minBounty, Math.min(20, apples)),
    );
    const why = crime.inPound
        ? `${crime.animal.name} is already in the pound.`
        : crime.escapedUntil
          ? `${crime.animal.name} slipped away. Try again ${fromNow(crime.escapedUntil)}.`
          : !crime.here
            ? `${crime.animal.name} was last seen in ${crime.lastSeen}. Walk there first.`
            : null;

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border bg-card p-3 text-sm">
            <span className="text-xl" aria-hidden>
                {speciesEmoji(crime.animal.species)}
            </span>
            <div className="min-w-0 flex-1">
                <p className="font-semibold">
                    <ProfileLink animal={crime.animal} /> {crime.deed}
                </p>
                <p className="text-xs text-muted-foreground">
                    {clockTime(crime.at)} · last seen in {crime.lastSeen} ·
                    catch them {fromNow(crime.until)} at the latest
                </p>
            </div>
            <Button
                size="sm"
                variant="destructive"
                disabled={why !== null}
                onClick={() => act(catchRoute.url(crime.crimeId))}
            >
                <Gavel className="size-4" /> Catch them
            </Button>
            {crime.warranted ? (
                <span className="text-xs font-semibold text-orange-300">
                    🎯 A warrant is out for them
                </span>
            ) : (
                <div className="flex items-center gap-2">
                    <Input
                        type="number"
                        min={rules.minBounty}
                        max={rules.maxBounty}
                        value={bounty}
                        onChange={(e) => setBounty(Number(e.target.value))}
                        className="h-8 w-20"
                        aria-label="Bounty in Apples"
                    />
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={bounty < rules.minBounty || bounty > apples}
                        onClick={() =>
                            act(issue.url(), {
                                player_id: crime.animal.id,
                                bounty,
                            })
                        }
                    >
                        🎯 Put a bounty
                    </Button>
                </div>
            )}
            <WhyNot reason={why} />
            {!crime.warranted && bounty > apples && (
                <WhyNot
                    reason={`You have ${apples} Apples. Swap Pineapples for Apples on the Apples page.`}
                />
            )}
        </li>
    );
}
