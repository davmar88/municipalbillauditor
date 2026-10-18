import { Head, router } from '@inertiajs/react';
import { Clock3, RefreshCw, Wheat } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { act, shortWhen } from '@/lib/game';
import { buy, check, swap } from '@/routes/play/apples';

type Pack = {
    id: string;
    apples: number;
    /** In cents. */
    zar: number;
    /** In cents. */
    usd: number;
    /** Percent saved on the smallest pack's price per Apple. */
    save: number;
};

type Way = 'paystack' | 'paypal';

type Props = {
    packs: Pack[];
    onSale: boolean;
    ways: Record<Way, boolean>;
    cornPerApple: number;
    pending: {
        id: number;
        apples: number;
        price: string;
        via: Way;
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
    contact: string | null;
};

/** "R95", "R95.50". */
function rand(cents: number): string {
    return `R${(cents / 100).toLocaleString('en-ZA', {
        minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
        maximumFractionDigits: 2,
    })}`;
}

/** "$5.70". */
function dollars(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

/**
 * Apples, bought with real money: packs to buy with Paystack or PayPal,
 * swapping Apples for Corn, and where your Apples came from and went.
 */
export default function ApplesPage({
    packs,
    onSale,
    ways,
    cornPerApple,
    pending,
    history,
    contact,
}: Props) {
    const [buying, setBuying] = useState<string | null>(null);
    const open = ways.paystack || ways.paypal;

    function pay(pack: Pack, via: Way) {
        setBuying(`${pack.id}:${via}`);
        router.post(
            buy.url(),
            { pack: pack.id, via },
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
                        Apples are bought with real money. Put them on the
                        bounty of an outlaw, give them to your faction's store,
                        or swap them for Corn. Bigger packs cost less per Apple.
                    </p>
                </header>

                {!open && (
                    <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                        {onSale
                            ? "Paying isn't open right now. Try again soon."
                            : "Apple packs aren't on sale yet. They open soon."}
                    </p>
                )}

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
                                {rand(pack.zar)} · {dollars(pack.usd)}
                                {pack.save > 0 && (
                                    <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-bold whitespace-nowrap text-emerald-300">
                                        Save {pack.save}%
                                    </span>
                                )}
                            </p>
                            {open && (
                                <div className="mt-auto flex flex-col gap-2">
                                    {ways.paystack && (
                                        <Button
                                            disabled={buying !== null}
                                            onClick={() =>
                                                pay(pack, 'paystack')
                                            }
                                        >
                                            {buying === `${pack.id}:paystack`
                                                ? 'Opening Paystack…'
                                                : `Pay ${rand(pack.zar)} with Paystack`}
                                        </Button>
                                    )}
                                    {ways.paypal && (
                                        <Button
                                            variant="secondary"
                                            disabled={buying !== null}
                                            onClick={() => pay(pack, 'paypal')}
                                        >
                                            {buying === `${pack.id}:paypal`
                                                ? 'Opening PayPal…'
                                                : `Pay ${dollars(pack.usd)} with PayPal`}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

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
                                        {purchase.apples} Apples for{' '}
                                        {purchase.price} with{' '}
                                        {purchase.via === 'paystack'
                                            ? 'Paystack'
                                            : 'PayPal'}
                                        , {shortWhen(purchase.at)}
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

                <Swap cornPerApple={cornPerApple} />

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
                    Paystack takes payments in rand and PayPal in US dollars; we
                    never see your card. Apples arrive as soon as the payment
                    clears.
                    {contact &&
                        ` Questions about a payment? Write to ${contact} with the date and amount.`}
                </p>
            </div>
        </>
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
