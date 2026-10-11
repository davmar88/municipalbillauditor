import { Head, Link } from '@inertiajs/react';
import { Search, Upload } from 'lucide-react';
import { useState } from 'react';
import { Chip, portfolioTone } from '@/components/audit/chip';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { StatTile } from '@/components/audit/stat-tile';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { portfolio } from '@/routes';
import { index as bills } from '@/routes/bills';
import type { PortfolioRow, PortfolioState } from '@/types';

type Props = {
    kpis: {
        units: number;
        checked: number;
        missing: number;
        openFlags: number;
        flaggedUnits: number;
        atStake: string;
        dueSoon: number;
        firstDue: string | null;
        creditedThisYear: string;
        resolvedThisYear: number;
    };
    rows: PortfolioRow[];
    due: {
        propertyId: number;
        label: string;
        date: string;
        action: string;
        overdue: boolean;
        href: string;
    }[];
    flagsByType: { type: string; label: string; count: number }[];
};

type Filter = 'all' | 'flags' | 'in_dispute' | 'missing';

const filters: { value: Filter; label: string; states: PortfolioState[] }[] = [
    { value: 'all', label: 'All', states: [] },
    { value: 'flags', label: 'Flagged', states: ['flags'] },
    {
        value: 'in_dispute',
        label: 'In dispute',
        states: ['in_dispute', 'escalated'],
    },
    { value: 'missing', label: 'Missing bill', states: ['missing'] },
];

const dayLabel = new Intl.DateTimeFormat('en-ZA', {
    weekday: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});

export default function Portfolio({ kpis, rows, due, flagsByType }: Props) {
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const active = filters.find((f) => f.value === filter)!;
    const needle = query.trim().toLowerCase();
    const visible = rows.filter(
        (row) =>
            (active.states.length === 0 || active.states.includes(row.state)) &&
            (needle === '' || row.label.toLowerCase().includes(needle)),
    );
    const maxFlags = Math.max(1, ...flagsByType.map((f) => f.count));

    return (
        <>
            <Head title="Portfolio" />
            <PageBody>
                <PageHeader
                    title="Portfolio"
                    description={`${kpis.units} ${kpis.units === 1 ? 'unit' : 'units'}, sorted by what needs you first.`}
                    actions={
                        <Button asChild>
                            <Link href={bills()}>
                                <Upload aria-hidden />
                                Upload bills
                            </Link>
                        </Button>
                    }
                />

                <section
                    aria-label="This billing cycle"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <StatTile
                        label="Bills checked"
                        value={`${kpis.checked} / ${kpis.units}`}
                        detail={
                            kpis.missing > 0
                                ? `Chase ${kpis.missing} missing ${kpis.missing === 1 ? 'bill' : 'bills'}`
                                : 'Every unit has a recent bill'
                        }
                    />
                    <StatTile
                        label="Open flags"
                        value={kpis.openFlags}
                        tone={kpis.openFlags > 0 ? 'flag' : 'default'}
                        detail={
                            kpis.openFlags > 0
                                ? `on ${kpis.flaggedUnits} ${kpis.flaggedUnits === 1 ? 'unit' : 'units'}, up to ${kpis.atStake} at stake`
                                : 'Nothing flagged right now'
                        }
                    />
                    <StatTile
                        label="Deadlines in the next 7 days"
                        value={kpis.dueSoon}
                        detail={
                            kpis.firstDue
                                ? `First: ${kpis.firstDue}`
                                : 'None this week'
                        }
                    />
                    <StatTile
                        label="Credited back this year"
                        value={kpis.creditedThisYear}
                        detail={`from ${kpis.resolvedThisYear} resolved ${kpis.resolvedThisYear === 1 ? 'dispute' : 'disputes'}`}
                    />
                </section>

                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <section
                        aria-labelledby="units-h"
                        className="flex min-w-0 flex-col gap-4 rounded-lg border bg-card p-5"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 id="units-h" className="text-lg font-bold">
                                Units
                            </h2>
                            <label className="relative w-full sm:w-64">
                                <span className="sr-only">Find a unit</span>
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden
                                />
                                <Input
                                    type="search"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Find a unit"
                                    className="pl-9"
                                />
                            </label>
                        </div>

                        <div
                            role="group"
                            aria-label="Show"
                            className="flex flex-wrap gap-2"
                        >
                            {filters.map((f) => {
                                const count =
                                    f.states.length === 0
                                        ? rows.length
                                        : rows.filter((r) =>
                                              f.states.includes(r.state),
                                          ).length;

                                return (
                                    <button
                                        key={f.value}
                                        type="button"
                                        aria-pressed={filter === f.value}
                                        onClick={() => setFilter(f.value)}
                                        className={cn(
                                            'min-h-10 rounded-full border px-3.5 text-sm font-semibold',
                                            filter === f.value
                                                ? 'border-foreground bg-foreground text-background'
                                                : 'bg-card text-foreground/80 hover:bg-accent',
                                        )}
                                    >
                                        {f.label}{' '}
                                        <span className="figures font-normal">
                                            {count}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        {visible.length === 0 ? (
                            <p className="py-6 text-center text-muted-foreground">
                                {rows.length === 0
                                    ? 'Add your properties, then upload or forward their bills.'
                                    : 'No units match.'}
                            </p>
                        ) : (
                            <UnitsTable rows={visible} />
                        )}
                        <p className="text-sm text-muted-foreground">
                            Sorted by most urgent · {visible.length} of{' '}
                            {rows.length}
                        </p>
                    </section>

                    <div className="flex flex-col gap-6">
                        <section
                            aria-labelledby="due-h"
                            className="flex flex-col gap-3 rounded-lg border bg-card p-5"
                        >
                            <h2 id="due-h" className="text-lg font-bold">
                                Due this week
                            </h2>
                            {due.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing due in the next 7 days.
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {due.map((item) => (
                                        <li key={item.propertyId}>
                                            <Link
                                                href={item.href}
                                                className="flex gap-3 rounded-md hover:bg-accent/60"
                                            >
                                                <span
                                                    className={cn(
                                                        'figures w-16 shrink-0 text-xs leading-6 font-semibold uppercase',
                                                        item.overdue
                                                            ? 'text-flag-ink'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {item.overdue
                                                        ? 'Overdue'
                                                        : dayLabel.format(
                                                              new Date(
                                                                  item.date,
                                                              ),
                                                          )}
                                                </span>
                                                <span className="flex flex-col text-sm">
                                                    <span className="font-semibold">
                                                        {item.label}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {item.action}
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section
                            aria-labelledby="types-h"
                            className="flex flex-col gap-3 rounded-lg border bg-card p-5"
                        >
                            <h2 id="types-h" className="text-lg font-bold">
                                Open flags by type
                            </h2>
                            <ul className="flex flex-col gap-3">
                                {flagsByType.map((type) => (
                                    <li
                                        key={type.type}
                                        className="flex flex-col gap-1"
                                    >
                                        <span className="flex justify-between gap-2 text-sm">
                                            <span>{type.label}</span>
                                            <span className="font-semibold">
                                                {type.count}
                                            </span>
                                        </span>
                                        <span
                                            aria-hidden
                                            className="h-2.5 overflow-hidden bg-muted"
                                        >
                                            <span
                                                className="block h-full rounded-r-[4px] bg-chart-accent"
                                                style={{
                                                    width: `${(type.count / maxFlags) * 100}%`,
                                                }}
                                            />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>
                </div>
            </PageBody>
        </>
    );
}

Portfolio.layout = {
    breadcrumbs: [{ title: 'Portfolio', href: portfolio() }],
};

function UnitsTable({ rows }: { rows: PortfolioRow[] }) {
    return (
        <>
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                            <th scope="col" className="py-2 pr-3 font-medium">
                                PROPERTY · UNIT
                            </th>
                            <th scope="col" className="px-3 py-2 font-medium">
                                METRO
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                LATEST BILL
                            </th>
                            <th scope="col" className="px-3 py-2 font-medium">
                                STATUS
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                AT STAKE
                            </th>
                            <th scope="col" className="py-2 pl-3 font-medium">
                                NEXT DEADLINE
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.propertyId}
                                className="relative border-b border-dotted last:border-0 hover:bg-accent/50"
                            >
                                <th
                                    scope="row"
                                    className="py-3 pr-3 text-left font-semibold whitespace-nowrap"
                                >
                                    <Link
                                        href={row.href}
                                        className="after:absolute after:inset-0 focus-visible:underline"
                                    >
                                        {row.label}
                                    </Link>
                                </th>
                                <td className="px-3 py-3">{row.metro}</td>
                                <td className="figures px-3 py-3 text-right">
                                    {row.billMonth && row.billTotal && (
                                        <span className="block font-sans text-xs text-muted-foreground">
                                            {row.billMonth}
                                        </span>
                                    )}
                                    {row.billTotal ?? (
                                        <span className="font-sans text-muted-foreground">
                                            none yet
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-3">
                                    <Chip tone={portfolioTone(row.state)}>
                                        {row.chip}
                                    </Chip>
                                </td>
                                <td className="figures px-3 py-3 text-right">
                                    {row.atStake ?? '–'}
                                </td>
                                <td className="py-3 pl-3">
                                    {row.deadlineLabel ?? '–'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul className="flex flex-col divide-y md:hidden">
                {rows.map((row) => (
                    <li key={row.propertyId}>
                        <Link
                            href={row.href}
                            className="flex flex-col gap-1.5 py-3"
                        >
                            <span className="flex items-center justify-between gap-2">
                                <span className="font-semibold">
                                    {row.label}
                                </span>
                                <Chip tone={portfolioTone(row.state)}>
                                    {row.chip}
                                </Chip>
                            </span>
                            <span className="flex justify-between gap-2 text-sm text-muted-foreground">
                                <span>{row.deadlineLabel ?? row.metro}</span>
                                {row.atStake && (
                                    <span className="figures">
                                        {row.atStake}
                                    </span>
                                )}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </>
    );
}
