import { Form, Head, Link } from '@inertiajs/react';
import { FlaskConical, KeyRound, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { act, shortWhen } from '@/lib/game';
import { cashouts } from '@/routes/admin';
import { forget, test, update } from '@/routes/admin/settings';

type Props = {
    settings: {
        applesOnSale: boolean;
        cashoutOpen: boolean;
        /** "••••7a3f" when saved: the key itself never comes back. */
        paystackSecretKey: string | null;
        paystackLive: boolean;
        paypalClientId: string | null;
        paypalClientSecret: string | null;
        paypalMode: 'sandbox' | 'live';
        anthropicApiKey: string | null;
        /** No key saved here, but the server's .env has one. */
        anthropicFromServer: boolean;
    };
    ready: { paystack: boolean; paypal: boolean };
    webhookUrl: string;
    sales: {
        count: number;
        /** In cents. */
        zar: number;
        /** In cents. */
        usd: number;
        sold: number;
        /** Apples still with animals, factions, bounties and cash-outs waiting. */
        held: number;
        /** Apples paid out for money. */
        cashedOut: number;
        kept: number;
        review: number;
        /** Animals whose Orchard membership is running. */
        members: number;
    };
    purchases: {
        id: number;
        animal: string;
        apples: number;
        price: string;
        via: 'paystack' | 'paypal';
        reference: string;
        status: 'pending' | 'paid' | 'failed' | 'review';
        at: string;
    }[];
};

const STATUS: Record<Props['purchases'][number]['status'], string> = {
    pending: 'Waiting',
    paid: 'Paid',
    failed: 'Not paid',
    review: 'Check by hand',
};

/**
 * The owner's page: payment and Claude keys, whether Apple packs are on
 * sale and cash-outs open, and what the sales brought in.
 */
export default function AdminSettings({
    settings,
    ready,
    webhookUrl,
    sales,
    purchases,
}: Props) {
    return (
        <>
            <Head title="Admin" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <KeyRound className="size-6 text-primary" aria-hidden />{' '}
                        Admin
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Payment keys, Apple sales and cash-outs, and what the
                        sales brought in. Keys are stored encrypted, and once
                        saved this page only shows their last four characters.
                    </p>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Money in">
                        R{(sales.zar / 100).toLocaleString('en-ZA')} · $
                        {(sales.usd / 100).toFixed(2)}
                        <span className="block text-xs font-normal text-muted-foreground">
                            from {sales.count}{' '}
                            {sales.count === 1 ? 'sale' : 'sales'}
                        </span>
                    </Stat>
                    <Stat label="Apples sold">
                        {sales.sold.toLocaleString('en-ZA')}
                        <span className="block text-xs font-normal text-muted-foreground">
                            {sales.held.toLocaleString('en-ZA')} still in the
                            game, {sales.cashedOut.toLocaleString('en-ZA')}{' '}
                            cashed out
                        </span>
                    </Stat>
                    <Stat label="Kept by the game">
                        {sales.kept.toLocaleString('en-ZA')} Apples
                        <span className="block text-xs font-normal text-muted-foreground">
                            from its share of bounties and shop sales,
                            memberships and swaps for Corn
                        </span>
                    </Stat>
                    <Stat label="Orchard members">
                        {sales.members.toLocaleString('en-ZA')}
                        <span className="block text-xs font-normal text-muted-foreground">
                            with a membership running now
                        </span>
                    </Stat>
                </section>
                {sales.review > 0 && (
                    <p className="rounded-md border border-orange-500/40 bg-orange-500/10 px-3 py-2 text-sm text-orange-200">
                        {sales.review}{' '}
                        {sales.review === 1
                            ? "payment didn't"
                            : "payments didn't"}{' '}
                        match its pack. Look it up in Paystack or PayPal by its
                        reference below.
                    </p>
                )}

                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={[
                        'paystack_secret_key',
                        'paypal_client_secret',
                        'anthropic_api_key',
                    ]}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card title="Apple packs">
                                <div className="grid gap-2">
                                    <Label htmlFor="apples_on_sale">
                                        Sales
                                    </Label>
                                    <NativeSelect
                                        id="apples_on_sale"
                                        name="apples_on_sale"
                                        defaultValue={
                                            settings.applesOnSale ? '1' : '0'
                                        }
                                    >
                                        <option value="1">On sale</option>
                                        <option value="0">Not on sale</option>
                                    </NativeSelect>
                                    <InputError
                                        message={errors.apples_on_sale}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Players can buy with Paystack{' '}
                                        {ready.paystack
                                            ? '(ready)'
                                            : '(no key yet)'}{' '}
                                        and PayPal{' '}
                                        {ready.paypal
                                            ? '(ready)'
                                            : '(no keys yet)'}
                                        .
                                    </p>
                                </div>
                            </Card>

                            <Card
                                title="Cash-outs"
                                actions={
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        asChild
                                    >
                                        <Link href={cashouts()}>
                                            See the queue
                                        </Link>
                                    </Button>
                                }
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="cashout_open">
                                        Cashing out
                                    </Label>
                                    <NativeSelect
                                        id="cashout_open"
                                        name="cashout_open"
                                        defaultValue={
                                            settings.cashoutOpen ? '1' : '0'
                                        }
                                    >
                                        <option value="0">Closed</option>
                                        <option value="1">Open</option>
                                    </NativeSelect>
                                    <InputError message={errors.cashout_open} />
                                    <p className="rounded-md border border-orange-500/40 bg-orange-500/10 px-3 py-2 text-xs text-orange-200">
                                        Open this only once a lawyer has
                                        confirmed the game may pay out real
                                        money. Dice decide raids and crimes, and
                                        Apples pass between animals in bounties
                                        and shop sales, so cash-outs may need a
                                        gambling or payments licence.
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Animals cash out by bank transfer
                                        through Paystack, by PayPal, or in
                                        Bitcoin you send by hand. Each one waits
                                        for you on the Cash-outs page.
                                    </p>
                                </div>
                            </Card>

                            <Card
                                title="Paystack, in rand"
                                actions={
                                    <KeyActions
                                        service="paystack"
                                        removable={
                                            settings.paystackSecretKey
                                                ? 'paystack_secret_key'
                                                : null
                                        }
                                    />
                                }
                            >
                                <SecretField
                                    name="paystack_secret_key"
                                    label="Secret key"
                                    saved={settings.paystackSecretKey}
                                    placeholder="sk_live_…"
                                    error={errors.paystack_secret_key}
                                    note={
                                        settings.paystackSecretKey
                                            ? settings.paystackLive
                                                ? 'A live key: real money.'
                                                : 'A test key: no real money moves.'
                                            : 'From Paystack: Settings, API Keys & Webhooks.'
                                    }
                                />
                                <div className="grid gap-1">
                                    <Label>Webhook URL</Label>
                                    <div className="flex items-center gap-2">
                                        <code className="min-w-0 flex-1 truncate rounded-md bg-muted px-2 py-1.5 text-xs">
                                            {webhookUrl}
                                        </code>
                                        <CopyButton value={webhookUrl} />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Paste it into Paystack's Webhook URL, so
                                        Apples arrive even when a player closes
                                        the page before coming back, and
                                        cash-outs settle when the bank answers.
                                    </p>
                                </div>
                            </Card>

                            <Card
                                title="PayPal, in US dollars"
                                actions={
                                    <KeyActions
                                        service="paypal"
                                        removable={
                                            settings.paypalClientSecret
                                                ? 'paypal_client_secret'
                                                : null
                                        }
                                    />
                                }
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="paypal_client_id">
                                        Client ID
                                    </Label>
                                    <Input
                                        id="paypal_client_id"
                                        name="paypal_client_id"
                                        defaultValue={
                                            settings.paypalClientId ?? ''
                                        }
                                        autoComplete="off"
                                    />
                                    <InputError
                                        message={errors.paypal_client_id}
                                    />
                                </div>
                                <SecretField
                                    name="paypal_client_secret"
                                    label="Secret"
                                    saved={settings.paypalClientSecret}
                                    error={errors.paypal_client_secret}
                                    note="From developer.paypal.com: Apps & Credentials. PayPal needs no webhook. Cash-outs need Payouts turned on for the app."
                                />
                                <div className="grid gap-2">
                                    <Label htmlFor="paypal_mode">Mode</Label>
                                    <NativeSelect
                                        id="paypal_mode"
                                        name="paypal_mode"
                                        defaultValue={settings.paypalMode}
                                    >
                                        <option value="sandbox">
                                            Sandbox: test money
                                        </option>
                                        <option value="live">
                                            Live: real money
                                        </option>
                                    </NativeSelect>
                                    <InputError message={errors.paypal_mode} />
                                </div>
                            </Card>

                            <Card
                                title="Claude, for translating chat"
                                actions={
                                    <KeyActions
                                        service="claude"
                                        removable={
                                            settings.anthropicApiKey
                                                ? 'anthropic_api_key'
                                                : null
                                        }
                                    />
                                }
                            >
                                <SecretField
                                    name="anthropic_api_key"
                                    label="API key"
                                    saved={settings.anthropicApiKey}
                                    placeholder="sk-ant-…"
                                    error={errors.anthropic_api_key}
                                    note={
                                        settings.anthropicFromServer
                                            ? "Using the key in the server's .env until you save one here."
                                            : 'From console.anthropic.com. Without one, the Translate button is off.'
                                    }
                                />
                            </Card>

                            <div>
                                <Button disabled={processing}>Save</Button>
                            </div>
                        </>
                    )}
                </Form>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Latest purchases</h2>
                    {purchases.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No purchases yet.
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y rounded-xl border bg-card text-sm">
                            {purchases.map((purchase) => (
                                <li
                                    key={purchase.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                >
                                    <span className="font-semibold">
                                        {purchase.animal}
                                    </span>
                                    <span>
                                        {purchase.apples} Apples ·{' '}
                                        {purchase.price} ·{' '}
                                        {purchase.via === 'paystack'
                                            ? 'Paystack'
                                            : 'PayPal'}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {purchase.reference}
                                    </span>
                                    <span className="ml-auto text-xs">
                                        {STATUS[purchase.status]} ·{' '}
                                        {shortWhen(purchase.at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
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

function Card({
    title,
    actions,
    children,
}: {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-bold">{title}</h2>
                {actions}
            </div>
            {children}
        </section>
    );
}

function SecretField({
    name,
    label,
    saved,
    placeholder,
    error,
    note,
}: {
    name: string;
    label: string;
    saved: string | null;
    placeholder?: string;
    error?: string;
    note: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input
                id={name}
                name={name}
                type="password"
                autoComplete="off"
                placeholder={
                    saved
                        ? `Saved (${saved}). Type a new one to replace it.`
                        : placeholder
                }
            />
            <InputError message={error} />
            <p className="text-xs text-muted-foreground">{note}</p>
        </div>
    );
}

function KeyActions({
    service,
    removable,
}: {
    service: 'paystack' | 'paypal' | 'claude';
    removable: string | null;
}) {
    return (
        <span className="flex items-center gap-1">
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => act(test.url(service))}
            >
                <FlaskConical className="size-4" /> Test
            </Button>
            {removable && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => act(forget.url(removable), {}, 'delete')}
                >
                    <Trash2 className="size-4" /> Remove
                </Button>
            )}
        </span>
    );
}
