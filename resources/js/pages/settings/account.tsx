import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, update } from '@/routes/account';
import { destroy as destroyBills } from '@/routes/account/bills';
import { destroy as destroyCard } from '@/routes/account/card';
import { update as updateDisputes } from '@/routes/account/disputes';
import { index as credit } from '@/routes/credit';
import { AddressText } from '@/components/address-text';

type Props = {
    account: {
        name: string;
        kind: string;
        kindLabel: string;
        forwardingAddress: string;
        pricing: string;
        mandateOn: string | null;
        autoSend: boolean;
        billCount: number;
        card: { last4: string; brand: string; expiry: string | null } | null;
    };
};

export default function Account({ account }: Props) {
    return (
        <>
            <Head title="Account" />

            <h1 className="sr-only">Account settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Account"
                    description={account.kindLabel}
                />
                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {account.kind === 'household'
                                        ? 'Account name'
                                        : 'Organisation name'}
                                </Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    defaultValue={account.name}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Save
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-3">
                <Heading
                    variant="small"
                    title="Your forwarding address"
                    description="Forward e-bills here and we check them as they arrive. Only send bills: anything else is ignored."
                />
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted px-4 py-3">
                    <span className="figures text-sm [overflow-wrap:anywhere]">
                        <AddressText address={account.forwardingAddress} />
                    </span>
                    <CopyButton value={account.forwardingAddress} />
                </div>
            </div>

            <div className="space-y-3">
                <Heading
                    variant="small"
                    title="Sending disputes for you"
                    description={
                        account.mandateOn
                            ? `You authorised us on ${account.mandateOn} to send disputes about your account to the municipality on your behalf, and to follow them up and escalate them.`
                            : 'We need your permission before we email a dispute to the municipality for you. Nothing is sent without it.'
                    }
                />
                <Form
                    {...updateDisputes.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-3"
                >
                    {({ processing }) => (
                        <>
                            <Toggle
                                name="mandate"
                                label="Send disputes about my account on my behalf"
                                defaultChecked={account.mandateOn !== null}
                            />
                            <Toggle
                                name="auto_send"
                                label="Send a dispute without asking me when a bill shows only likely errors"
                                defaultChecked={account.autoSend}
                            />
                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Save
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-3">
                <Heading variant="small" title="What you pay" />
                <p className="text-sm">{account.pricing}</p>
                <Link
                    href={credit()}
                    className="text-sm font-semibold text-primary underline underline-offset-[3px]"
                >
                    Credit and billing
                </Link>
            </div>

            {account.card && (
                <div className="space-y-3">
                    <Heading
                        variant="small"
                        title="Saved card"
                        description={`Your monthly fee is charged to your ${account.card.brand} card ending ${account.card.last4}${account.card.expiry ? `, expiring ${account.card.expiry}` : ''}. Paystack keeps the card details; we only keep a code to charge it.`}
                    />
                    <Form
                        {...destroyCard.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button variant="outline" disabled={processing}>
                                {processing && <Spinner />}
                                Remove this card
                            </Button>
                        )}
                    </Form>
                </div>
            )}

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="Delete your bills"
                    description={`You have ${account.billCount} ${account.billCount === 1 ? 'bill' : 'bills'} stored. Deleting them removes the files, findings and disputes for good.`}
                />
                <Form
                    {...destroyBills.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-3 rounded-lg border border-flag/40 p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="confirm">
                                    Type DELETE to confirm
                                </Label>
                                <Input
                                    id="confirm"
                                    name="confirm"
                                    autoComplete="off"
                                    className="font-mono"
                                />
                                <InputError message={errors.confirm} />
                            </div>
                            <Button
                                variant="destructive"
                                disabled={processing || account.billCount === 0}
                            >
                                {processing && <Spinner />}
                                Delete all bills
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

/** A checkbox that always posts 1 or 0, so unticking it is saved too. */
function Toggle({
    name,
    label,
    defaultChecked,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
}) {
    const [checked, setChecked] = useState(defaultChecked);

    return (
        <label className="flex items-start gap-3 text-sm">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => setChecked(e.target.checked)}
                className="mt-0.5 size-4 accent-primary"
            />
            {label}
        </label>
    );
}

Account.layout = {
    breadcrumbs: [{ title: 'Account settings', href: edit() }],
};
