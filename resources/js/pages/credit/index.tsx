import { Form, Head } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { Chip } from '@/components/audit/chip';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { StatTile } from '@/components/audit/stat-tile';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index, topUp } from '@/routes/credit';
import { pay } from '@/routes/credit/invoices';

type Props = {
    balance: string;
    prices: { billCheck: string; dispute: string };
    plan: {
        monthlyFee: string;
        includedChecks: number;
        usedChecks: number | null;
        card: { last4: string; brand: string; expiry: string | null } | null;
    } | null;
    packs: { value: string; label: string }[];
    cardPayments: boolean;
    transactions: {
        id: number;
        on: string;
        description: string;
        amount: string;
        balance: string;
        kind: string;
    }[];
    invoices: {
        id: number;
        description: string;
        amount: string;
        status: string;
        paidOn: string | null;
        payable: boolean;
    }[];
};

export default function CreditIndex({
    balance,
    prices,
    plan,
    packs,
    cardPayments,
    transactions,
    invoices,
}: Props) {
    return (
        <>
            <Head title="Credit and billing" />
            <PageBody className="max-w-4xl">
                <PageHeader
                    title="Credit and billing"
                    description={
                        plan
                            ? `Your plan is ${plan.monthlyFee} a month. Bill checks beyond the ${plan.includedChecks} included each month come from your prepaid credit.`
                            : `You pay per job from prepaid credit: ${prices.billCheck} to check a bill, ${prices.dispute} for a dispute. We always show the price first.`
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatTile label="Your credit" value={balance} />
                    {plan ? (
                        <StatTile
                            label="Bill checks this month"
                            value={`${plan.usedChecks ?? 0} of ${plan.includedChecks}`}
                            detail="Included in your plan. Disputes are unlimited."
                        />
                    ) : (
                        <StatTile
                            label="Prices"
                            value={prices.billCheck}
                            detail={`a bill check · ${prices.dispute} a dispute`}
                        />
                    )}
                </div>

                <section
                    aria-labelledby="top-up-h"
                    className="flex flex-col gap-4 rounded-lg border bg-card p-5 sm:p-6"
                >
                    <div className="flex flex-col gap-1">
                        <h2 id="top-up-h" className="text-lg font-bold">
                            Top up
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Pay once by card through Paystack. Credit doesn't
                            expire, and bills waiting for credit are checked as
                            soon as it arrives.
                        </p>
                    </div>
                    {cardPayments ? (
                        <div className="flex flex-wrap gap-2">
                            {packs.map((pack) => (
                                <Form key={pack.value} {...topUp.form()}>
                                    {({ processing, errors }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="amount"
                                                value={pack.value}
                                            />
                                            <Button
                                                type="submit"
                                                size="lg"
                                                variant="outline"
                                                className="figures min-w-24"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {pack.label}
                                            </Button>
                                            <InputError
                                                message={errors.amount}
                                            />
                                        </>
                                    )}
                                </Form>
                            ))}
                        </div>
                    ) : (
                        <p className="rounded-md bg-muted px-4 py-3 text-sm">
                            Card payments aren't switched on yet. To top up by
                            EFT, contact us and our team adds it to your credit.
                        </p>
                    )}
                </section>

                {plan && (
                    <section
                        aria-labelledby="invoices-h"
                        className="flex flex-col gap-4 rounded-lg border bg-card p-5 sm:p-6"
                    >
                        <div className="flex flex-col gap-1">
                            <h2 id="invoices-h" className="text-lg font-bold">
                                Monthly fees
                            </h2>
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <CreditCard className="size-4" aria-hidden />
                                {plan.card
                                    ? `Charged each month to your ${plan.card.brand} card ending ${plan.card.last4}. Change it by paying an invoice with another card, or remove it in your account settings.`
                                    : 'Pay your first invoice by card and we charge the same card each month.'}
                            </p>
                        </div>
                        {invoices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Your first fee is raised on the 1st of the
                                month.
                            </p>
                        ) : (
                            <ul className="flex flex-col divide-y">
                                {invoices.map((invoice) => (
                                    <li
                                        key={invoice.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                                    >
                                        <span className="flex flex-col">
                                            <span className="font-semibold">
                                                {invoice.description}
                                            </span>
                                            <span className="figures text-sm text-muted-foreground">
                                                {invoice.amount}
                                            </span>
                                        </span>
                                        {invoice.payable ? (
                                            cardPayments ? (
                                                <Form {...pay.form(invoice.id)}>
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {processing && (
                                                                <Spinner />
                                                            )}
                                                            Pay now
                                                        </Button>
                                                    )}
                                                </Form>
                                            ) : (
                                                <Chip tone="flag-outline">
                                                    Due
                                                </Chip>
                                            )
                                        ) : (
                                            <Chip tone="neutral">
                                                {invoice.paidOn
                                                    ? `Paid ${invoice.paidOn}`
                                                    : invoice.status}
                                            </Chip>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <section
                    aria-labelledby="history-h"
                    className="flex flex-col gap-3"
                >
                    <h2 id="history-h" className="text-lg font-bold">
                        History
                    </h2>
                    {transactions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing yet. Top-ups and jobs show here.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border bg-card">
                            <table className="w-full min-w-[520px] text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th
                                            scope="col"
                                            className="px-4 py-2 font-medium"
                                        >
                                            Date
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 font-medium"
                                        >
                                            What
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 text-right font-medium"
                                        >
                                            Amount
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 text-right font-medium"
                                        >
                                            Balance
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.map((t) => (
                                        <tr
                                            key={t.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {t.on}
                                            </td>
                                            <td className="px-4 py-2">
                                                {t.description}
                                            </td>
                                            <td className="figures px-4 py-2 text-right">
                                                {t.amount}
                                            </td>
                                            <td className="figures px-4 py-2 text-right">
                                                {t.balance}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </PageBody>
        </>
    );
}

CreditIndex.layout = {
    breadcrumbs: [{ title: 'Credit and billing', href: index() }],
};
