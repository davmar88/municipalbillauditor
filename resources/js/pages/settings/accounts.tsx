import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { destroy, index, store, switchMethod, update } from '@/routes/accounts';
import type { OrganisationKind } from '@/types';

type Account = {
    id: number;
    name: string;
    kind: OrganisationKind;
    kindLabel: string;
    balance: string;
    hasCredit: boolean;
    billCount: number;
    current: boolean;
};

type Props = {
    accounts: Account[];
    kinds: { value: OrganisationKind; label: string }[];
};

const namePlaceholder: Record<OrganisationKind, string> = {
    household: 'For example: Our home',
    agent: 'Agency or portfolio name',
    business: 'Business or body corporate name',
};

export default function Accounts({ accounts, kinds }: Props) {
    const { url } = usePage();
    const adding = new URLSearchParams(url.split('?')[1] ?? '').has('add');
    const [kind, setKind] = useState<OrganisationKind>('business');

    return (
        <>
            <Head title="Your accounts" />

            <h1 className="sr-only">Your accounts</h1>

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="Your accounts"
                    description="A home, a rental portfolio, a business: each account has its own prepaid credit, bills and forwarding address. Switch between them here or from the menu."
                />
                <ul className="space-y-3">
                    {accounts.map((account) => (
                        <AccountRow
                            key={account.id}
                            account={account}
                            kinds={kinds}
                            onlyOne={accounts.length === 1}
                        />
                    ))}
                </ul>
            </div>

            <div className="space-y-4" id="add">
                <Heading
                    variant="small"
                    title="Add an account"
                    description="Pay as you go, like your others: no monthly or setup fees. You start working in it straight away."
                />
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-4 rounded-lg border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="new_kind">Type</Label>
                                <NativeSelect
                                    id="new_kind"
                                    name="kind"
                                    value={kind}
                                    onChange={(e) =>
                                        setKind(
                                            e.target.value as OrganisationKind,
                                        )
                                    }
                                >
                                    {kinds.map((k) => (
                                        <option key={k.value} value={k.value}>
                                            {k.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errors.kind} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="new_name">Name</Label>
                                <Input
                                    id="new_name"
                                    name="name"
                                    required
                                    autoFocus={adding}
                                    autoComplete="organization"
                                    placeholder={namePlaceholder[kind]}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Add account
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function AccountRow({
    account,
    kinds,
    onlyOne,
}: {
    account: Account;
    kinds: Props['kinds'];
    onlyOne: boolean;
}) {
    const [mode, setMode] = useState<'view' | 'edit' | 'remove'>('view');
    const removable = !onlyOne && !account.hasCredit;

    return (
        <li
            className={
                account.current
                    ? 'rounded-lg border border-primary/60 bg-primary/5 p-4'
                    : 'rounded-lg border p-4'
            }
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="truncate font-semibold">
                            {account.name}
                        </span>
                        {account.current && (
                            <span className="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground">
                                Current
                            </span>
                        )}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        {account.kindLabel} ·{' '}
                        <span className="figures">{account.balance}</span>{' '}
                        credit · {account.billCount}{' '}
                        {account.billCount === 1 ? 'bill' : 'bills'}
                    </span>
                </div>
                <div className="flex flex-wrap gap-2">
                    {!account.current && (
                        <Form
                            {...switchMethod.form(account.id)}
                            data-test={`switch-${account.id}`}
                        >
                            {({ processing }) => (
                                <Button size="sm" disabled={processing}>
                                    {processing && <Spinner />}
                                    Switch to it
                                </Button>
                            )}
                        </Form>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            setMode(mode === 'edit' ? 'view' : 'edit')
                        }
                        aria-expanded={mode === 'edit'}
                    >
                        Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            setMode(mode === 'remove' ? 'view' : 'remove')
                        }
                        aria-expanded={mode === 'remove'}
                    >
                        Remove
                    </Button>
                </div>
            </div>

            {mode === 'edit' && (
                <Form
                    {...update.form(account.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setMode('view')}
                    className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`name_${account.id}`}>
                                    Name
                                </Label>
                                <Input
                                    id={`name_${account.id}`}
                                    name="name"
                                    required
                                    defaultValue={account.name}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor={`kind_${account.id}`}>
                                    Type
                                </Label>
                                <NativeSelect
                                    id={`kind_${account.id}`}
                                    name="kind"
                                    defaultValue={account.kind}
                                >
                                    {kinds.map((k) => (
                                        <option key={k.value} value={k.value}>
                                            {k.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errors.kind} />
                            </div>
                            <Button
                                disabled={processing}
                                className="sm:col-span-2 sm:justify-self-start"
                            >
                                {processing && <Spinner />}
                                Save
                            </Button>
                        </>
                    )}
                </Form>
            )}

            {mode === 'remove' && (
                <div className="mt-4 space-y-3 border-t pt-4">
                    {onlyOne ? (
                        <p className="text-sm">
                            This is your only account, so it can't be removed.
                            Add another first.
                        </p>
                    ) : account.hasCredit ? (
                        <p className="text-sm">
                            This account still has{' '}
                            <span className="figures">{account.balance}</span>{' '}
                            of prepaid credit. Use it, or ask us to refund it,
                            before removing the account.
                        </p>
                    ) : (
                        <Form
                            {...destroy.form(account.id)}
                            options={{ preserveScroll: true }}
                            className="space-y-3 rounded-lg border border-flag/40 p-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <p className="text-sm">
                                        Removing {account.name} deletes its{' '}
                                        {account.billCount}{' '}
                                        {account.billCount === 1
                                            ? 'bill'
                                            : 'bills'}
                                        , their files, findings and disputes for
                                        good, unless someone else is on the
                                        account too.
                                    </p>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`confirm_${account.id}`}
                                        >
                                            Type DELETE to confirm
                                        </Label>
                                        <Input
                                            id={`confirm_${account.id}`}
                                            name="confirm"
                                            autoComplete="off"
                                            className="font-mono"
                                        />
                                        <InputError message={errors.confirm} />
                                    </div>
                                    <Button
                                        variant="destructive"
                                        disabled={processing || !removable}
                                    >
                                        {processing && <Spinner />}
                                        Remove account
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </li>
    );
}

Accounts.layout = {
    breadcrumbs: [{ title: 'Your accounts', href: index() }],
};
