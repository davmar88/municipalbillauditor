import { Head, Link, router, usePage } from '@inertiajs/react';
import { Banknote, Clock3, RefreshCw, Wheat } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { act, dayMonth, dollars, shortWhen } from '@/lib/game';
import { cashout } from '@/routes/play';
import { buy, check, exchange, swap } from '@/routes/play/apples';
import { harvest, plant } from '@/routes/play/trees';
import { join, refill } from '@/routes/play/orchard';

/** Pineapples, bought with real money. */
type PineapplePack = {
    id: string;
    pineapples: number;
    /** In US cents. */
    usd: number;
    /** Percent saved on the smallest pack's price per Pineapple. */
    save: number;
};

/** Apple trees, planted with Pineapples. */
type TreesInfo = {
    price: number;
    /** The Apples a tree gives in all. */
    apples: number;
    perDay: number;
    basketSize: number;
    saplingDays: number;
    most: number;
    trees: {
        id: number;
        /** When a sapling fruits, or null once it does. */
        fruitsAt: string | null;
        grown: number;
        basket: number;
        total: number;
        full: boolean;
    }[];
};

/** Apples, bought with Pineapples. */
type ApplePack = {
    id: string;
    apples: number;
    pineapples: number;
    /** Percent saved on the smallest pack's price per Apple. */
    save: number;
};

type Props = {
    pineapplePacks: PineapplePack[];
    applePacks: ApplePack[];
    trees: TreesInfo;
    onSale: boolean;
    /** Whether paying with PayPal is open. */
    open: boolean;
    cornPerApple: number;
    pending: {
        id: number;
        pineapples: number;
        price: string;
        /** Paid, but not as expected: our team checks it by hand. */
        review: boolean;
        at: string;
    }[];
    history: {
        id: number;
        amount: number;
        reason: string;
        at: string | null;
    }[];
    orchard: {
        price: number;
        days: number;
        /** When the membership ends, or null for a non-member. */
        until: string | null;
        refillReady: boolean;
        /** Shelves in a member's shop. */
        shelves: number;
    };
    contact: string | null;
};

/**
 * Pineapples bought with PayPal and swapped for packs of Apples, Orchard
 * membership, swapping Apples for Corn, and where your Apples came from and
 * went. Cashing out has a page of its own.
 */
export default function ApplesPage({
    pineapplePacks,
    applePacks,
    trees,
    onSale,
    open,
    cornPerApple,
    pending,
    history,
    orchard,
    contact,
}: Props) {
    const [buying, setBuying] = useState<string | null>(null);

    const { player } = usePage().props;
    const pineapples = player?.pineapples ?? 0;

    function pay(pack: PineapplePack) {
        setBuying(pack.id);
        router.post(
            buy.url(),
            { pack: pack.id },
            {
                preserveScroll: true,
                onFinish: () => setBuying(null),
            },
        );
    }

    return (
        <>
            <Head title="Apples" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <GameBar />
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <span aria-hidden>🍎</span> Apples
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Buy Pineapples, then swap them for packs of Apples.
                        Spend Apples in shops that take them, join the Orchard,
                        put them on the bounty of an outlaw, give them to your
                        faction's store, or swap them for Corn. Bigger packs
                        cost less.
                    </p>
                </header>

                {!open && (
                    <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                        {onSale
                            ? "Paying isn't open right now. Try again soon."
                            : "Pineapples aren't on sale yet. They open soon."}
                    </p>
                )}

                <h2 className="-mb-3 flex items-center gap-2 text-lg font-bold">
                    <span aria-hidden>🍍</span> Pineapples
                    <span className="text-sm font-semibold text-muted-foreground">
                        · you have {pineapples.toLocaleString('en-ZA')}
                    </span>
                </h2>
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {pineapplePacks.map((pack) => (
                        <li
                            key={pack.id}
                            className="flex flex-col gap-3 rounded-xl border bg-card p-4"
                        >
                            <p className="text-3xl font-extrabold whitespace-nowrap">
                                <span aria-hidden>🍍</span>{' '}
                                {pack.pineapples.toLocaleString('en-ZA')}
                                <span className="ml-1.5 text-sm font-semibold text-muted-foreground">
                                    Pineapples
                                </span>
                            </p>
                            <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                {dollars(pack.usd)}
                                {pack.save > 0 && (
                                    <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-bold whitespace-nowrap text-emerald-300">
                                        Save {pack.save}%
                                    </span>
                                )}
                            </p>
                            {open && (
                                <Button
                                    className="mt-auto"
                                    disabled={buying !== null}
                                    onClick={() => pay(pack)}
                                >
                                    {buying === pack.id
                                        ? 'Opening PayPal…'
                                        : `Pay ${dollars(pack.usd)} with PayPal`}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>

                <ApplePacks packs={applePacks} pineapples={pineapples} />

                <AppleTrees trees={trees} pineapples={pineapples} />

                {pending.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-lg font-bold">
                            Payments on the way
                        </h2>
                        <ul className="flex flex-col gap-2">
                            {pending.map((purchase) => (
                                <li
                                    key={purchase.id}
                                    className="flex flex-wrap items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm"
                                >
                                    <Clock3
                                        className="size-4 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <span>
                                        {purchase.pineapples.toLocaleString(
                                            'en-ZA',
                                        )}{' '}
                                        Pineapples for {purchase.price} with
                                        PayPal, {shortWhen(purchase.at)}
                                    </span>
                                    {purchase.review ? (
                                        <span className="ml-auto text-muted-foreground">
                                            Our team is checking it
                                        </span>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="ml-auto"
                                            onClick={() =>
                                                act(check.url(purchase.id))
                                            }
                                        >
                                            <RefreshCw className="size-4" />{' '}
                                            Check again
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <Orchard orchard={orchard} />

                <Swap cornPerApple={cornPerApple} />

                <section className="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-4">
                    <Banknote
                        className="size-5 shrink-0 text-emerald-300"
                        aria-hidden
                    />
                    <div className="min-w-0 flex-1">
                        <h2 className="font-bold">Cash out</h2>
                        <p className="text-sm text-muted-foreground">
                            Turn Apples you earned into money, paid to your
                            PayPal.
                        </p>
                    </div>
                    <Button variant="secondary" asChild>
                        <Link href={cashout()}>Cash out</Link>
                    </Button>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Your Apples</h2>
                    {history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing yet. Apples you buy, give and collect show
                            up here.
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y rounded-xl border bg-card text-sm">
                            {history.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex items-center gap-3 px-3 py-2"
                                >
                                    <span
                                        className={
                                            entry.amount > 0
                                                ? 'w-16 font-semibold text-emerald-300'
                                                : 'w-16 font-semibold text-muted-foreground'
                                        }
                                    >
                                        {entry.amount > 0 ? '+' : '−'}
                                        {Math.abs(entry.amount).toLocaleString(
                                            'en-ZA',
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate">
                                        {entry.reason}
                                    </span>
                                    {entry.at && (
                                        <span className="text-xs text-muted-foreground">
                                            {shortWhen(entry.at)}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <p className="text-xs text-muted-foreground">
                    PayPal takes payments in US dollars; we never see your card.
                    Pineapples arrive as soon as the payment clears.
                    {contact &&
                        ` Questions about a payment? Write to ${contact} with the date and amount.`}
                </p>
            </div>
        </>
    );
}

function Orchard({ orchard }: { orchard: Props['orchard'] }) {
    const { player } = usePage().props;
    const short = orchard.price - (player?.apples ?? 0);
    const full = (player?.horseshoes ?? 0) >= (player?.maxHorseshoes ?? 0);

    return (
        <section className="flex flex-col gap-3 rounded-xl border border-emerald-500/40 bg-card p-4">
            <h2 className="flex items-center gap-2 text-lg font-bold">
                <span aria-hidden>🌳</span> Orchard membership
            </h2>
            <p className="text-sm text-muted-foreground">
                {orchard.price} Apples for {orchard.days} days. There's no
                monthly billing: it ends unless you renew it.
            </p>
            <ul className="list-disc pl-5 text-sm">
                <li>One free horseshoe refill a day</li>
                <li>A 🌳 badge by your name</li>
                <li>
                    {orchard.shelves} shelves in your shop instead of{' '}
                    {orchard.shelves - 1}
                </li>
            </ul>
            {orchard.until && (
                <p className="text-sm font-semibold text-emerald-300">
                    You're a member until {dayMonth(orchard.until)}.
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    disabled={short > 0}
                    onClick={() =>
                        confirm(
                            orchard.until
                                ? `Add ${orchard.days} days to your membership for ${orchard.price} Apples?`
                                : `Join the Orchard for ${orchard.days} days for ${orchard.price} Apples?`,
                        ) && act(join.url())
                    }
                >
                    {short > 0
                        ? `Need ${short} more Apples`
                        : orchard.until
                          ? `Add ${orchard.days} days for ${orchard.price} Apples`
                          : `Join for ${orchard.price} Apples`}
                </Button>
                {orchard.refillReady ? (
                    <Button
                        variant="secondary"
                        disabled={full}
                        title={full ? 'Your horseshoes are full' : undefined}
                        onClick={() => act(refill.url())}
                    >
                        {full ? 'Horseshoes full' : "Take today's free refill"}
                    </Button>
                ) : (
                    orchard.until && (
                        <span className="text-sm text-muted-foreground">
                            Today's free refill is used. The next one is ready
                            tomorrow.
                        </span>
                    )
                )}
            </div>
        </section>
    );
}

function Swap({ cornPerApple }: { cornPerApple: number }) {
    const [apples, setApples] = useState(1);
    const valid = Number.isInteger(apples) && apples >= 1;

    return (
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <h2 className="flex items-center gap-2 text-lg font-bold">
                <Wheat className="size-5 text-amber-300" aria-hidden /> Swap
                Apples for Corn
            </h2>
            <p className="text-sm text-muted-foreground">
                Every Apple buys {cornPerApple} Corn. Corn never turns back into
                Apples, and it shrinks to a fifth when a season ends; Apples
                don't.
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    type="number"
                    min={1}
                    value={apples}
                    onChange={(event) => setApples(Number(event.target.value))}
                    className="w-28"
                    aria-label="Apples to swap"
                />
                <span className="text-sm">
                    ={' '}
                    <span className="font-semibold">
                        {valid
                            ? (apples * cornPerApple).toLocaleString('en-ZA')
                            : 0}{' '}
                        Corn
                    </span>
                </span>
                <Button
                    variant="secondary"
                    disabled={!valid}
                    onClick={() => act(swap.url(), { apples })}
                >
                    Swap
                </Button>
            </div>
        </section>
    );
}

/** Packs of Apples, bought with Pineapples. */
function ApplePacks({
    packs,
    pineapples,
}: {
    packs: ApplePack[];
    pineapples: number;
}) {
    return (
        <section className="flex flex-col gap-3">
            <div>
                <h2 className="flex items-center gap-2 text-lg font-bold">
                    <span aria-hidden>🍎</span> Apple packs
                </h2>
                <p className="text-sm text-muted-foreground">
                    Swap your Pineapples for Apples.
                </p>
            </div>
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {packs.map((pack) => (
                    <li
                        key={pack.id}
                        className="flex flex-col gap-3 rounded-xl border bg-card p-4"
                    >
                        <p className="text-3xl font-extrabold whitespace-nowrap">
                            <span aria-hidden>🍎</span>{' '}
                            {pack.apples.toLocaleString('en-ZA')}
                            <span className="ml-1.5 text-sm font-semibold text-muted-foreground">
                                Apples
                            </span>
                        </p>
                        <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span aria-hidden>🍍</span>
                            {pack.pineapples.toLocaleString('en-ZA')} Pineapples
                            {pack.save > 0 && (
                                <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-bold whitespace-nowrap text-emerald-300">
                                    Save {pack.save}%
                                </span>
                            )}
                        </p>
                        <Button
                            variant="secondary"
                            className="mt-auto"
                            disabled={pineapples < pack.pineapples}
                            onClick={() =>
                                act(exchange.url(), { pack: pack.id })
                            }
                        >
                            {pineapples < pack.pineapples
                                ? `Needs ${(pack.pineapples - pineapples).toLocaleString('en-ZA')} more Pineapples`
                                : 'Swap for these Apples'}
                        </Button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Apple trees: plant them with Pineapples, then harvest their Apples. */
function AppleTrees({
    trees,
    pineapples,
}: {
    trees: TreesInfo;
    pineapples: number;
}) {
    const ready = trees.trees.reduce((sum, tree) => sum + tree.basket, 0);
    const room = trees.trees.length < trees.most;

    return (
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div>
                <h2 className="flex items-center gap-2 text-lg font-bold">
                    <span aria-hidden>🌳</span> Apple trees
                </h2>
                <p className="text-sm text-muted-foreground">
                    Plant a tree for {trees.price.toLocaleString('en-ZA')}{' '}
                    Pineapples. It fruits after {trees.saplingDays} days, then
                    grows {trees.perDay} Apples a day until it has given{' '}
                    {trees.apples}: the cheapest way to Apples. Its basket holds{' '}
                    {trees.basketSize} Apples and the tree waits when it's full,
                    so come back to harvest.
                </p>
            </div>

            {trees.trees.length > 0 && (
                <ul className="grid gap-2 sm:grid-cols-2">
                    {trees.trees.map((tree) => (
                        <li
                            key={tree.id}
                            className="flex flex-col gap-1.5 rounded-lg border px-3 py-2 text-sm"
                        >
                            <span className="flex items-center gap-2 font-semibold">
                                <span aria-hidden>
                                    {tree.fruitsAt ? '🌱' : '🌳'}
                                </span>
                                {tree.fruitsAt
                                    ? `Fruits ${shortWhen(tree.fruitsAt)}`
                                    : `${tree.basket} ${tree.basket === 1 ? 'Apple' : 'Apples'} in the basket`}
                                {tree.full && (
                                    <span className="rounded-full bg-orange-500/15 px-2 py-0.5 text-xs font-bold text-orange-300">
                                        Full
                                    </span>
                                )}
                            </span>
                            <span className="h-1.5 overflow-hidden rounded-full bg-muted">
                                <span
                                    className="block h-full bg-emerald-400"
                                    style={{
                                        width: `${Math.round((tree.grown / tree.total) * 100)}%`,
                                    }}
                                />
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {tree.grown} of {tree.total} Apples grown
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {ready > 0 && (
                    <Button onClick={() => act(harvest.url())}>
                        Harvest {ready} {ready === 1 ? 'Apple' : 'Apples'}
                    </Button>
                )}
                <Button
                    variant="secondary"
                    disabled={!room || pineapples < trees.price}
                    onClick={() => act(plant.url())}
                >
                    Plant a tree ({trees.price.toLocaleString('en-ZA')}{' '}
                    Pineapples)
                </Button>
                <span className="text-xs text-muted-foreground">
                    {room
                        ? `${trees.trees.length} of ${trees.most} trees growing.`
                        : `You have ${trees.most} trees growing.`}
                </span>
            </div>
        </section>
    );
}
