import { Form, Head } from '@inertiajs/react';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { StatTile } from '@/components/audit/stat-tile';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index, topUp } from '@/routes/credit';

type Props = {
    balance: string;
    prices: { billCheck: string; dispute: string };
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
};

export default function CreditIndex({
    balance,
    prices,
    packs,
    cardPayments,
    transactions,
}: Props) {
    return (
        <>
            <Head title="Credit and billing" />
            <PageBody className="max-w-4xl">
                <PageHeader
                    title="Credit and billing"
                    description={`You pay as you go from prepaid credit: ${prices.billCheck} to check a bill, ${prices.dispute} for a dispute. No monthly or setup fees, and we always show the price first.`}
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatTile label="Your credit" value={balance} />
                    <StatTile
                        label="Prices"
                        value={prices.billCheck}
                        detail={`a bill check · ${prices.dispute} a dispute`}
                    />
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
