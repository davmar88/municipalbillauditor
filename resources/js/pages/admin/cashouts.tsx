import { Head, Link } from '@inertiajs/react';
import { Banknote, RefreshCw, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { CopyButton } from '@/components/copy-button';
import type { PayoutMethod } from '@/components/game/cash-out';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { act, dollars, rand, shortWhen } from '@/lib/game';
import { admin } from '@/routes';
import {
    check,
    otp as sendOtp,
    paid,
    pay,
    reject,
    resendOtp,
} from '@/routes/admin/cashouts';

type Status = 'pending' | 'sending' | 'paid' | 'failed' | 'rejected';

type Card = {
    id: number;
    animal: string;
    apples: number;
    price: string;
    method: PayoutMethod;
    /** The animal's legal name. */
    name: string;
    /** "SA ID ••••9087" */
    document: string;
    /** "Absa, ••••6789" */
    label: string;
    /** In full, for sending Bitcoin by hand. */
    address: string | null;
    email: string | null;
    reference: string;
    transaction: string | null;
    status: Status;
    /** What went wrong, or why it was turned down. */
    note: string | null;
    at: string;
};

type Waiting = Card & {
    joined: string | null;
    /** When the animal last saved its payout details. */
    detailsChanged: string | null;
    /** Apples that came in over the last 30 days, by where from. */
    sources: {
        bought: number;
        sales: number;
        bounties: number;
        returned: number;
        other: number;
    } | null;
    paidBefore: number;
    /** Paystack is waiting for the OTP it texted you. */
    otp: boolean;
};

type Props = {
    open: boolean;
    ready: { paystack: boolean; paypal: boolean };
    live: { paystack: boolean; paypal: boolean };
    totals: { count: number; apples: number; zar: number; usd: number };
    queue: Waiting[];
    history: (Card & { by: string | null })[];
};

const WAY: Record<PayoutMethod, string> = {
    paystack: 'Bank transfer',
    paypal: 'PayPal',
    bitcoin: 'Bitcoin',
};

const STATUS: Record<Status, string> = {
    pending: 'Waiting for you',
    sending: 'On its way',
    paid: 'Paid',
    failed: "Didn't go through, Apples back",
    rejected: 'Turned down, Apples back',
};

/** "120 bought, 850 from shop sales". */
function sources(from: NonNullable<Waiting['sources']>): string {
    const parts = (
        [
            [from.bought, 'bought'],
            [from.sales, 'from shop sales'],
            [from.bounties, 'from bounties'],
            [from.returned, 'given back'],
            [from.other, 'from elsewhere'],
        ] as const
    )
        .filter(([apples]) => apples > 0)
        .map(([apples, label]) => `${apples.toLocaleString('en-ZA')} ${label}`);

    return parts.length > 0 ? parts.join(', ') : 'none came in';
}

/**
 * The owner's cash-out queue: pay each one through Paystack or PayPal,
 * send Bitcoin by hand and mark it paid, or turn it down.
 */
export default function AdminCashouts({
    open,
    ready,
    live,
    totals,
    queue,
    history,
}: Props) {
    const testing = [
        ready.paystack && !live.paystack ? 'Paystack' : null,
        ready.paypal && !live.paypal ? 'PayPal' : null,
    ].filter((name) => name !== null);

    return (
        <>
            <Head title="Cash-outs" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <Banknote className="size-6 text-primary" aria-hidden />{' '}
                        Cash-outs
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Every cash-out waits here for you. Pay sends it through
                        Paystack or PayPal. Bitcoin you send by hand from your
                        own wallet, then mark it paid with the transaction ID.
                        Turning one down gives the animal its Apples back.
                    </p>
                </header>

                {!open && (
                    <p className="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">
                        Cashing out is closed, so animals can't ask for new
                        cash-outs. You can still deal with the ones below. It
                        opens on the{' '}
                        <Link href={admin()} className="underline">
                            admin page
                        </Link>
                        .
                    </p>
                )}
                {testing.length > 0 && (
                    <p className="rounded-md border border-orange-500/40 bg-orange-500/10 px-3 py-2 text-sm text-orange-200">
                        {testing.join(' and ')}{' '}
                        {testing.length === 1 ? 'is' : 'are'} on test keys, so
                        no real money moves there.
                    </p>
                )}

                <section className="grid gap-3 sm:grid-cols-3">
                    <Stat label="Paid out">
                        {rand(totals.zar)} · {dollars(totals.usd)}
                        <span className="block text-xs font-normal text-muted-foreground">
                            in {totals.count}{' '}
                            {totals.count === 1 ? 'cash-out' : 'cash-outs'}
                        </span>
                    </Stat>
                    <Stat label="Apples cashed out">
                        {totals.apples.toLocaleString('en-ZA')}
                    </Stat>
                    <Stat label="Waiting">
                        {queue.length.toLocaleString('en-ZA')}
                    </Stat>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Waiting for you</h2>
                    {queue.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing waiting.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {queue.map((cashout) => (
                                <QueueItem
                                    key={cashout.id}
                                    cashout={cashout}
                                    ready={
                                        cashout.method === 'bitcoin' ||
                                        ready[cashout.method]
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Done</h2>
                    {history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No cash-outs yet.
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y rounded-xl border bg-card text-sm">
                            {history.map((cashout) => (
                                <li
                                    key={cashout.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                >
                                    <span className="font-semibold">
                                        {cashout.price}
                                    </span>
                                    <span>
                                        {cashout.animal} ·{' '}
                                        {cashout.apples.toLocaleString('en-ZA')}{' '}
                                        Apples · {WAY[cashout.method]}
                                    </span>
                                    <span className="ml-auto text-xs">
                                        {STATUS[cashout.status]}
                                        {cashout.by &&
                                            ` by ${cashout.by}`} ·{' '}
                                        {shortWhen(cashout.at)}
                                    </span>
                                    {cashout.status !== 'paid' &&
                                        cashout.note && (
                                            <span className="w-full text-xs text-muted-foreground">
                                                {cashout.note}
                                            </span>
                                        )}
                                    {cashout.status === 'paid' &&
                                        cashout.transaction && (
                                            <span className="w-full truncate text-xs text-muted-foreground">
                                                {cashout.transaction}
                                            </span>
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

function QueueItem({ cashout, ready }: { cashout: Waiting; ready: boolean }) {
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [transaction, setTransaction] = useState('');
    const [code, setCode] = useState('');
    const changedLately =
        cashout.detailsChanged !== null &&
        Date.now() - new Date(cashout.detailsChanged).getTime() <
            3 * 24 * 60 * 60 * 1000;

    return (
        <li className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-lg font-extrabold">
                    {cashout.price}{' '}
                    <span className="text-sm font-semibold text-muted-foreground">
                        for {cashout.apples.toLocaleString('en-ZA')} Apples by{' '}
                        {WAY[cashout.method]}
                    </span>
                </p>
                <span className="text-xs text-muted-foreground">
                    {STATUS[cashout.status]} · asked {shortWhen(cashout.at)}
                </span>
            </div>

            <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[8rem_1fr]">
                <Row label="Animal">
                    {cashout.animal}
                    {cashout.joined && `, joined ${shortWhen(cashout.joined)}`}
                    {cashout.paidBefore > 0
                        ? `, paid ${cashout.paidBefore} ${cashout.paidBefore === 1 ? 'time' : 'times'} before`
                        : ', first cash-out'}
                </Row>
                <Row label="Name and ID">
                    {cashout.name} · {cashout.document}
                </Row>
                <Row label="Paid to">{cashout.email ?? cashout.label}</Row>
                {cashout.address && (
                    <Row label="Address">
                        <span className="flex flex-wrap items-center gap-1">
                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs break-all">
                                {cashout.address}
                            </code>
                            <CopyButton value={cashout.address} />
                        </span>
                    </Row>
                )}
                {cashout.sources && (
                    <Row label="Last 30 days">{sources(cashout.sources)}</Row>
                )}
                <Row label="Reference">
                    <code className="text-xs">{cashout.reference}</code>
                </Row>
            </dl>

            {changedLately && cashout.detailsChanged && (
                <p className="flex items-start gap-2 rounded-md border border-orange-500/40 bg-orange-500/10 px-3 py-2 text-sm text-orange-200">
                    <TriangleAlert
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden
                    />
                    These payout details were saved{' '}
                    {shortWhen(cashout.detailsChanged)}. If the account was
                    taken over, the money goes to someone else, so make sure
                    it's really them.
                </p>
            )}
            {cashout.note && (
                <p className="rounded-md bg-muted px-3 py-2 text-sm">
                    {cashout.note}
                </p>
            )}

            {cashout.status === 'pending' && cashout.method !== 'bitcoin' && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        disabled={!ready}
                        onClick={() =>
                            confirm(
                                `Send ${cashout.price} to ${cashout.email ?? cashout.label}?`,
                            ) && act(pay.url(cashout.id))
                        }
                    >
                        Pay {cashout.price}
                    </Button>
                    {!ready && (
                        <span className="text-sm text-muted-foreground">
                            Add the{' '}
                            {cashout.method === 'paystack'
                                ? 'Paystack key'
                                : 'PayPal keys'}{' '}
                            on the admin page first.
                        </span>
                    )}
                </div>
            )}

            {cashout.status === 'pending' && cashout.method === 'bitcoin' && (
                <div className="flex flex-col gap-2">
                    <p className="text-sm text-muted-foreground">
                        Send Bitcoin worth {cashout.price} to the address above
                        from your wallet. Then paste the transaction ID from the
                        wallet's details of the payment.
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            value={transaction}
                            onChange={(event) =>
                                setTransaction(event.target.value)
                            }
                            placeholder="Transaction ID"
                            aria-label="Transaction ID"
                            autoComplete="off"
                            className="min-w-0 flex-1"
                        />
                        <Button
                            disabled={transaction.trim().length < 6}
                            onClick={() =>
                                act(paid.url(cashout.id), { transaction })
                            }
                        >
                            Mark paid
                        </Button>
                    </div>
                </div>
            )}

            {cashout.status === 'sending' && (
                <div className="flex flex-wrap items-center gap-2">
                    {cashout.otp && (
                        <>
                            <Input
                                value={code}
                                onChange={(event) =>
                                    setCode(event.target.value)
                                }
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                placeholder="OTP"
                                aria-label="OTP from Paystack"
                                className="w-28"
                            />
                            <Button
                                disabled={!/^\d{4,8}$/.test(code)}
                                onClick={() =>
                                    act(sendOtp.url(cashout.id), { otp: code })
                                }
                            >
                                Send
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => act(resendOtp.url(cashout.id))}
                            >
                                Text me a new OTP
                            </Button>
                        </>
                    )}
                    <Button
                        variant="secondary"
                        onClick={() => act(check.url(cashout.id))}
                    >
                        <RefreshCw className="size-4" /> Check
                    </Button>
                </div>
            )}

            {cashout.status === 'pending' &&
                (rejecting ? (
                    <div className="flex flex-col gap-2 rounded-md border p-3">
                        <Input
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="Why? The animal sees this."
                            aria-label="Why you're turning it down"
                            maxLength={200}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="destructive"
                                disabled={reason.trim().length < 3}
                                onClick={() =>
                                    act(reject.url(cashout.id), { reason })
                                }
                            >
                                Turn down and give the Apples back
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => setRejecting(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setRejecting(true)}
                        >
                            Turn down
                        </Button>
                    </div>
                ))}
        </li>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0">{children}</dd>
        </>
    );
}

function Stat({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-lg font-extrabold">{children}</p>
        </div>
    );
}
