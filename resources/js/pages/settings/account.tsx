import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, update } from '@/routes/account';
import { destroy as destroyBills } from '@/routes/account/bills';
import { AddressText } from '@/components/address-text';

type Props = {
    account: {
        name: string;
        kind: string;
        kindLabel: string;
        forwardingAddress: string;
        pricing: string;
        billCount: number;
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
                <Heading variant="small" title="What you pay" />
                <p className="text-sm">{account.pricing}</p>
            </div>

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

Account.layout = {
    breadcrumbs: [{ title: 'Account settings', href: edit() }],
};
