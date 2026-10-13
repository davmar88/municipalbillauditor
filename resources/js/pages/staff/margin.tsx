import { Head, Link, router } from '@inertiajs/react';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { StatTile } from '@/components/audit/stat-tile';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { index, margin } from '@/routes/staff';

type Props = {
    report: {
        month: string;
        monthLabel: string;
        topUps: string;
        monthlyFees: string;
        revenue: string;
        claudeCost: string;
        margin: string;
        marginPercent: string | null;
        calls: number;
        unpricedCalls: number;
        byPurpose: {
            purpose: string;
            calls: number;
            cost: string;
            perCall: string;
        }[];
        byOrganisation: { name: string; calls: number; cost: string }[];
    };
    months: { value: string; label: string }[];
};

const purposes: Record<string, string> = {
    bill_read: 'Reading bills',
    reply_read: 'Reading municipal replies',
};

export default function StaffMargin({ report, months }: Props) {
    return (
        <>
            <Head title="Margin" />
            <PageBody>
                <PageHeader
                    title="Margin"
                    description="Money received (top-ups and monthly fees paid) against what Claude cost us, from the real token counts of every call."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={index()}>Work queue</Link>
                        </Button>
                    }
                />

                <div className="grid max-w-xs gap-1.5">
                    <Label htmlFor="month">Month</Label>
                    <NativeSelect
                        id="month"
                        value={report.month}
                        onChange={(e) =>
                            router.get(
                                margin.url({
                                    query: { month: e.target.value },
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {months.map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Revenue"
                        value={report.revenue}
                        detail={`${report.topUps} top-ups · ${report.monthlyFees} monthly fees`}
                    />
                    <StatTile
                        label="Claude cost"
                        value={report.claudeCost}
                        detail={`${report.calls} ${report.calls === 1 ? 'call' : 'calls'}`}
                    />
                    <StatTile
                        label="Margin"
                        value={report.margin}
                        detail={report.marginPercent ?? 'No revenue yet'}
                    />
                </div>

                {report.unpricedCalls > 0 && (
                    <p className="rounded-md bg-flag-soft px-4 py-3 text-sm text-flag-ink">
                        {report.unpricedCalls}{' '}
                        {report.unpricedCalls === 1
                            ? 'call used a model'
                            : 'calls used models'}{' '}
                        with no price in config, so the cost above leaves{' '}
                        {report.unpricedCalls === 1 ? 'it' : 'them'} out. Add
                        the price to config/auditor.php.
                    </p>
                )}

                <div className="grid items-start gap-6 lg:grid-cols-2">
                    <section className="flex flex-col gap-3">
                        <h2 className="text-lg font-bold">
                            What the cost was for
                        </h2>
                        <Table
                            headings={['Work', 'Calls', 'Cost', 'Per call']}
                            rows={report.byPurpose.map((row) => [
                                purposes[row.purpose] ?? row.purpose,
                                String(row.calls),
                                row.cost,
                                row.perCall,
                            ])}
                            empty="No Claude calls this month."
                        />
                    </section>
                    <section className="flex flex-col gap-3">
                        <h2 className="text-lg font-bold">
                            Costliest accounts
                        </h2>
                        <Table
                            headings={['Account', 'Calls', 'Cost']}
                            rows={report.byOrganisation.map((row) => [
                                row.name,
                                String(row.calls),
                                row.cost,
                            ])}
                            empty="No Claude calls this month."
                        />
                    </section>
                </div>
            </PageBody>
        </>
    );
}

StaffMargin.layout = {
    breadcrumbs: [
        { title: 'Work queue', href: index() },
        { title: 'Margin', href: margin() },
    ],
};

function Table({
    headings,
    rows,
    empty,
}: {
    headings: string[];
    rows: string[][];
    empty: string;
}) {
    if (rows.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border bg-card">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                        {headings.map((h, i) => (
                            <th
                                key={h}
                                scope="col"
                                className={`px-4 py-2 font-medium ${i > 0 ? 'text-right' : ''}`}
                            >
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((cells, r) => (
                        <tr key={r} className="border-b last:border-0">
                            {cells.map((cell, i) => (
                                <td
                                    key={i}
                                    className={`px-4 py-2 ${i > 0 ? 'figures text-right' : ''}`}
                                >
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
