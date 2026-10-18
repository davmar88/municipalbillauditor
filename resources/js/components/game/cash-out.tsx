import { Form } from '@inertiajs/react';
import { Banknote, Clock3 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { act, dollars, rand, shortWhen } from '@/lib/game';
import { account as saveAccount, request } from '@/routes/play/cashout';

export type PayoutMethod = 'paystack' | 'paypal' | 'bitcoin';

type CashoutStatus = 'pending' | 'sending' | 'paid' | 'failed' | 'rejected';

export type CashOutInfo = {
    open: boolean;
    ways: Record<PayoutMethod, boolean>;
    /** What 100 Apples pay, in cents. */
    per100: { ZAR: number; USD: number };
    min: number;
    dailyCap: number;
    holdDays: number;
    /** Apples bought lately, which wait before they can be cashed out. */
    held: number;
    /** The most the animal can cash out right now. */
    available: number;
    account: {
        method: PayoutMethod;
        /** "Absa, ••••6789" */
        label: string;
        name: string;
        country: 'ZA' | 'ZZ';
        documentType: 'id' | 'passport';
        /** "••••9087" */
        document: string;
        bankCode: string | null;
    } | null;
    banks: { code: string; name: string }[];
    history: {
        id: number;
        apples: number;
        price: string;
        label: string;
        status: CashoutStatus;
        /** Why our team turned it down. */
        reason: string | null;
        at: string;
    }[];
};

const WAYS: PayoutMethod[] = ['paystack', 'paypal', 'bitcoin'];

const WAY: Record<PayoutMethod, string> = {
    paystack: 'Bank transfer',
    paypal: 'PayPal',
    bitcoin: 'Bitcoin',
};

const STATUS: Record<CashoutStatus, string> = {
    pending: 'Waiting for our team',
    sending: 'On its way',
    paid: 'Paid',
    failed: "Didn't go through, Apples back",
    rejected: 'Turned down, Apples back',
};

/** What cashing out this many Apples pays, in cents, rounded down as the game does. */
function quote(
    apples: number,
    method: PayoutMethod,
    per100: CashOutInfo['per100'],
): string {
    return method === 'paypal'
        ? dollars(Math.floor((apples * per100.USD) / 100))
        : rand(Math.floor((apples * per100.ZAR) / 100));
}

/**
 * Cashing Apples out for money: by bank transfer, PayPal or Bitcoin, once
 * our team has checked each one.
 */
export function CashOut({ cashout }: { cashout: CashOutInfo }) {
    const [editing, setEditing] = useState(cashout.account === null);

    if (!cashout.open) {
        return (
            <section className="flex flex-col gap-1 rounded-xl border border-dashed p-4">
                <Heading />
                <p className="text-sm text-muted-foreground">
                    Cashing Apples out for money isn't open yet.
                </p>
            </section>
        );
    }

    const { account, per100 } = cashout;
    const waiting = cashout.history.find(
        (item) => item.status === 'pending' || item.status === 'sending',
    );

    return (
        <section className="flex flex-col gap-4 rounded-xl border bg-card p-4">
            <div className="flex flex-col gap-1">
                <Heading />
                <p className="text-sm text-muted-foreground">
                    100 Apples pay {rand(per100.ZAR)} by bank transfer or in
                    Bitcoin, or {dollars(per100.USD)} by PayPal. Cash out{' '}
                    {cashout.min.toLocaleString('en-ZA')} to{' '}
                    {cashout.dailyCap.toLocaleString('en-ZA')} Apples a day. Our
                    team checks every cash-out before it's sent.
                </p>
                {cashout.held > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {cashout.held.toLocaleString('en-ZA')} of your Apples
                        were bought in the last {cashout.holdDays} days. Bought
                        Apples wait {cashout.holdDays} days before they can be
                        cashed out.
                    </p>
                )}
            </div>

            {waiting && (
                <p className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                    <Clock3
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    Your cash-out of {waiting.price} for{' '}
                    {waiting.apples.toLocaleString('en-ZA')} Apples to{' '}
                    {waiting.label} is{' '}
                    {waiting.status === 'pending'
                        ? 'waiting for our team'
                        : 'on its way'}
                    .
                </p>
            )}

            {account && !editing && (
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                    <span>
                        Paid to <strong>{account.label}</strong>, in the name of{' '}
                        {account.name} (
                        {account.documentType === 'id' ? 'ID' : 'passport'}{' '}
                        {account.document}).
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditing(true)}
                    >
                        Change
                    </Button>
                </div>
            )}

            {editing && (
                <PayoutForm
                    cashout={cashout}
                    onSaved={() => setEditing(false)}
                    onCancel={account ? () => setEditing(false) : undefined}
                />
            )}

            {account &&
                !editing &&
                !waiting &&
                (cashout.ways[account.method] ? (
                    <Request cashout={cashout} method={account.method} />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Cashing out by {WAY[account.method]} isn't open right
                        now. Change your payout details to another way.
                    </p>
                ))}

            {cashout.history.length > 0 && (
                <ul className="flex flex-col divide-y rounded-lg border text-sm">
                    {cashout.history.map((item) => (
                        <li
                            key={item.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                        >
                            <span className="font-semibold">{item.price}</span>
                            <span className="text-muted-foreground">
                                {item.apples.toLocaleString('en-ZA')} Apples to{' '}
                                {item.label}
                            </span>
                            <span className="ml-auto text-xs">
                                {STATUS[item.status]} · {shortWhen(item.at)}
                            </span>
                            {item.reason && (
                                <span className="w-full text-xs text-muted-foreground">
                                    Our team said: {item.reason}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function Heading() {
    return (
        <h2 className="flex items-center gap-2 text-lg font-bold">
            <Banknote className="size-5 text-emerald-300" aria-hidden /> Cash
            out
        </h2>
    );
}

function Request({
    cashout,
    method,
}: {
    cashout: CashOutInfo;
    method: PayoutMethod;
}) {
    const [apples, setApples] = useState(cashout.min);
    const whole = Number.isInteger(apples) && apples > 0;
    const valid = whole && apples >= cashout.min && apples <= cashout.available;

    if (cashout.available < cashout.min) {
        return (
            <p className="text-sm text-muted-foreground">
                You can cash out once you have{' '}
                {cashout.min.toLocaleString('en-ZA')} Apples to cash out. Right
                now you can cash out {cashout.available.toLocaleString('en-ZA')}
                .
            </p>
        );
    }

    const price = quote(whole ? apples : 0, method, cashout.per100);

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm">
                You can cash out{' '}
                <strong>{cashout.available.toLocaleString('en-ZA')}</strong>{' '}
                Apples now.
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    type="number"
                    min={cashout.min}
                    max={cashout.available}
                    value={apples}
                    onChange={(event) => setApples(Number(event.target.value))}
                    className="w-32"
                    aria-label="Apples to cash out"
                />
                <span className="text-sm">
                    = <span className="font-semibold">{price}</span>
                </span>
                <Button
                    disabled={!valid}
                    onClick={() =>
                        confirm(
                            `Cash out ${apples.toLocaleString('en-ZA')} Apples for ${price}? The Apples leave your balance now, and come back if the cash-out doesn't go through.`,
                        ) && act(request.url(), { apples })
                    }
                >
                    Cash out
                </Button>
            </div>
            {whole && apples < cashout.min && (
                <p className="text-xs text-muted-foreground">
                    Cash out at least {cashout.min.toLocaleString('en-ZA')}{' '}
                    Apples.
                </p>
            )}
            {apples > cashout.available && (
                <p className="text-xs text-muted-foreground">
                    That's more than you can cash out now.
                </p>
            )}
            {method === 'bitcoin' && (
                <p className="text-xs text-muted-foreground">
                    Paid in Bitcoin worth the rand amount on the day our team
                    sends it.
                </p>
            )}
        </div>
    );
}

function PayoutForm({
    cashout,
    onSaved,
    onCancel,
}: {
    cashout: CashOutInfo;
    onSaved: () => void;
    onCancel?: () => void;
}) {
    const { account, ways, banks } = cashout;
    const [country, setCountry] = useState(account?.country ?? 'ZA');
    const [documentType, setDocumentType] = useState(
        account?.documentType ?? 'id',
    );
    const [method, setMethod] = useState<PayoutMethod>(
        account?.method ?? 'paystack',
    );
    // A bank transfer goes to a South African account only.
    const open = WAYS.filter(
        (way) => ways[way] && (way !== 'paystack' || country === 'ZA'),
    );
    const chosen = open.includes(method) ? method : open[0];
    const idType = country === 'ZA' ? documentType : 'passport';

    return (
        <Form
            {...saveAccount.form()}
            options={{ preserveScroll: true }}
            onSuccess={onSaved}
            className="flex flex-col gap-4 rounded-lg border p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div>
                        <h3 className="font-bold">Your payout details</h3>
                        <p className="text-xs text-muted-foreground">
                            Cash-outs go only to an account in your own name. We
                            keep these details encrypted, use them only to pay
                            you and check who you are, and delete them with your
                            account.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="country"
                            label="Where you live"
                            error={errors.country}
                        >
                            <NativeSelect
                                id="country"
                                name="country"
                                value={country}
                                onChange={(event) =>
                                    setCountry(
                                        event.target.value as 'ZA' | 'ZZ',
                                    )
                                }
                            >
                                <option value="ZA">South Africa</option>
                                <option value="ZZ">Another country</option>
                            </NativeSelect>
                        </Field>
                        <Field
                            id="legal_name"
                            label="Your full name, as on your ID"
                            error={errors.legal_name}
                        >
                            <Input
                                id="legal_name"
                                name="legal_name"
                                defaultValue={account?.name ?? ''}
                                autoComplete="name"
                                required
                            />
                        </Field>
                        {country === 'ZA' ? (
                            <Field
                                id="document_type"
                                label="Your ID"
                                error={errors.document_type}
                            >
                                <NativeSelect
                                    id="document_type"
                                    name="document_type"
                                    value={documentType}
                                    onChange={(event) =>
                                        setDocumentType(
                                            event.target.value as
                                                | 'id'
                                                | 'passport',
                                        )
                                    }
                                >
                                    <option value="id">South African ID</option>
                                    <option value="passport">Passport</option>
                                </NativeSelect>
                            </Field>
                        ) : (
                            <input
                                type="hidden"
                                name="document_type"
                                value="passport"
                            />
                        )}
                        <Field
                            id="document_number"
                            label={
                                idType === 'id'
                                    ? 'ID number'
                                    : 'Passport number'
                            }
                            error={errors.document_number}
                        >
                            <Input
                                id="document_number"
                                name="document_number"
                                inputMode={idType === 'id' ? 'numeric' : 'text'}
                                autoComplete="off"
                                placeholder={
                                    account
                                        ? `Type it again (saved: ${account.document})`
                                        : undefined
                                }
                                required
                            />
                        </Field>
                    </div>

                    {open.length === 0 || chosen === undefined ? (
                        <p className="text-sm text-muted-foreground">
                            Bank transfers are for South African accounts, and
                            no other way to be paid is open right now.
                        </p>
                    ) : (
                        <>
                            <Field
                                id="method"
                                label="How you want to be paid"
                                error={errors.method}
                            >
                                <NativeSelect
                                    id="method"
                                    name="method"
                                    value={chosen}
                                    onChange={(event) =>
                                        setMethod(
                                            event.target.value as PayoutMethod,
                                        )
                                    }
                                >
                                    {open.map((way) => (
                                        <option key={way} value={way}>
                                            {WAY[way]}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>

                            {chosen === 'paystack' && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        id="bank_code"
                                        label="Your bank"
                                        error={errors.bank_code}
                                    >
                                        <NativeSelect
                                            id="bank_code"
                                            name="bank_code"
                                            defaultValue={
                                                account?.bankCode ?? ''
                                            }
                                            required
                                        >
                                            <option value="" disabled>
                                                Pick your bank
                                            </option>
                                            {banks.map((bank) => (
                                                <option
                                                    key={bank.code}
                                                    value={bank.code}
                                                >
                                                    {bank.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field
                                        id="account_number"
                                        label="Account number"
                                        error={errors.account_number}
                                    >
                                        <Input
                                            id="account_number"
                                            name="account_number"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            required
                                        />
                                    </Field>
                                    <p className="text-xs text-muted-foreground sm:col-span-2">
                                        The bank checks the account is in your
                                        name and ID number before we save it.
                                    </p>
                                </div>
                            )}

                            {chosen === 'paypal' && (
                                <Field
                                    id="paypal_email"
                                    label="Your PayPal email"
                                    error={errors.paypal_email}
                                    note="Paid in US dollars. PayPal may charge you to change them into rand."
                                >
                                    <Input
                                        id="paypal_email"
                                        name="paypal_email"
                                        type="email"
                                        autoComplete="email"
                                        required
                                    />
                                </Field>
                            )}

                            {chosen === 'bitcoin' && (
                                <Field
                                    id="bitcoin_address"
                                    label="Your Bitcoin address"
                                    error={errors.bitcoin_address}
                                    note="Copy it from your wallet's Receive screen. Bitcoin sent to a wrong address can't be got back."
                                >
                                    <Input
                                        id="bitcoin_address"
                                        name="bitcoin_address"
                                        autoComplete="off"
                                        spellCheck={false}
                                        placeholder="bc1…"
                                        required
                                    />
                                </Field>
                            )}
                        </>
                    )}

                    <div className="flex items-start gap-3">
                        <Checkbox id="mine" name="mine" value="1" />
                        <div className="grid gap-1">
                            <Label htmlFor="mine">
                                These details are mine, and I'm 18 or older
                            </Label>
                            <InputError message={errors.mine} />
                        </div>
                    </div>

                    <InputError message={errors.details} />

                    <div className="flex flex-wrap gap-2">
                        <Button disabled={processing || chosen === undefined}>
                            {processing ? 'Saving…' : 'Save payout details'}
                        </Button>
                        {onCancel && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onCancel}
                            >
                                Cancel
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

function Field({
    id,
    label,
    error,
    note,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    note?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
            {note && <p className="text-xs text-muted-foreground">{note}</p>}
        </div>
    );
}
