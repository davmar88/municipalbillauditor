import { Head, Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChartLine } from 'lucide-react';
import { useMemo, useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { ItemPreview } from '@/components/game/item-preview';
import { cn } from '@/lib/utils';
import { market } from '@/routes/play';
import type { DesignCard } from '@/types/game';

type Row = {
    id: number;
    name: string;
    template: DesignCard['template'];
    kind: string;
    colours: string[];
    pattern: DesignCard['pattern'];
    maker: string;
    made: number;
    circulating: number;
    sold: number;
    soldWeek: number;
    last: { price: number; currency: 'corn' | 'apples'; at: string } | null;
    average: number | null;
    change: number | null;
    onSale: {
        count: number;
        lowest: number;
        currency: 'corn' | 'apples';
    } | null;
};

type Kind = {
    kind: string;
    template: string;
    circulating: number;
    sold: number;
    soldWeek: number;
    designs: number;
};

type Props = {
    board: Row[];
    kinds: Kind[];
    most: Row[];
    least: Row[];
    totals: {
        designs: number;
        circulating: number;
        sold: number;
        soldWeek: number;
    };
    weekDays: number;
};

type SortKey = 'sold' | 'soldWeek' | 'circulating' | 'change' | 'name';

function price(amount: number, currency: 'corn' | 'apples'): string {
    return `${amount.toLocaleString('en-ZA')} ${currency === 'apples' ? '🍎' : '🌽'}`;
}

function Change({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">–</span>;
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-semibold',
                value > 0
                    ? 'text-emerald-500'
                    : value < 0
                      ? 'text-red-500'
                      : 'text-muted-foreground',
            )}
        >
            {value > 0 ? (
                <ArrowUp className="size-3" aria-hidden />
            ) : value < 0 ? (
                <ArrowDown className="size-3" aria-hidden />
            ) : null}
            {Math.abs(value)}%
        </span>
    );
}

/**
 * The Market as an exchange: which items sell most and least, how many
 * of each are about, and how prices moved this week.
 */
export default function ExchangePage({
    board,
    kinds,
    most,
    least,
    totals,
    weekDays,
}: Props) {
    const [sort, setSort] = useState<SortKey>('sold');
    const sorted = useMemo(
        () =>
            [...board].sort((a, b) =>
                sort === 'name'
                    ? a.name.localeCompare(b.name)
                    : (b[sort] ?? -Infinity) - (a[sort] ?? -Infinity),
            ),
        [board, sort],
    );

    return (
        <>
            <Head title="Exchange" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <GameBar showNextStep={false} />
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                        <ChartLine className="size-6" aria-hidden /> Exchange
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Every item trading in the valley: how many are about,
                        what sells and what doesn't, and how prices moved over
                        the last {weekDays} days. Buy and sell in the{' '}
                        <Link
                            href={market()}
                            className="underline underline-offset-2"
                        >
                            Market
                        </Link>
                        .
                    </p>
                </header>

                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        ['Designs trading', totals.designs],
                        ['Copies about', totals.circulating],
                        ['Sold in all', totals.sold],
                        [`Sold in ${weekDays} days`, totals.soldWeek],
                    ].map(([label, value]) => (
                        <div
                            key={label as string}
                            className="rounded-xl border bg-card p-3"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-2xl font-extrabold">
                                {(value as number).toLocaleString('en-ZA')}
                            </dd>
                        </div>
                    ))}
                </dl>

                {board.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                        Nothing has been made yet. Design something in the
                        Market and it shows up here.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Leaders title="Sells the most" rows={most} />
                            <Leaders title="Sells the least" rows={least} />
                        </div>

                        <section className="flex flex-col gap-2">
                            <h2 className="text-lg font-bold">
                                By kind of item
                            </h2>
                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full min-w-[520px] text-sm">
                                    <thead className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2">Kind</th>
                                            <th className="px-3 py-2 text-right">
                                                Designs
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                About
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Sold
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                {weekDays} days
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kinds.map((kind) => (
                                            <tr
                                                key={kind.template}
                                                className="border-b last:border-0"
                                            >
                                                <td className="px-3 py-2 font-semibold">
                                                    {kind.kind}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {kind.designs}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {kind.circulating.toLocaleString(
                                                        'en-ZA',
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {kind.sold.toLocaleString(
                                                        'en-ZA',
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {kind.soldWeek.toLocaleString(
                                                        'en-ZA',
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section className="flex flex-col gap-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-lg font-bold">
                                    Every item
                                </h2>
                                <label className="flex items-center gap-2 text-sm">
                                    Sort by
                                    <select
                                        value={sort}
                                        onChange={(e) =>
                                            setSort(e.target.value as SortKey)
                                        }
                                        className="rounded-md border bg-background px-2 py-1"
                                    >
                                        <option value="sold">Most sold</option>
                                        <option value="soldWeek">
                                            Sold this week
                                        </option>
                                        <option value="circulating">
                                            Most about
                                        </option>
                                        <option value="change">
                                            Price rising
                                        </option>
                                        <option value="name">Name</option>
                                    </select>
                                </label>
                            </div>
                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full min-w-[760px] text-sm">
                                    <thead className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2">Item</th>
                                            <th className="px-3 py-2 text-right">
                                                Made
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                About
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Sold
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Last price
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Week avg
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Move
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                On shelves
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sorted.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="px-3 py-2">
                                                    <span className="flex items-center gap-2">
                                                        <ItemPreview
                                                            design={row}
                                                            size={32}
                                                        />
                                                        <span className="min-w-0">
                                                            <span className="block truncate font-semibold">
                                                                {row.name}
                                                            </span>
                                                            <span className="block truncate text-xs text-muted-foreground">
                                                                {row.kind} by{' '}
                                                                {row.maker}
                                                            </span>
                                                        </span>
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {row.made}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {row.circulating}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {row.sold}
                                                    {row.soldWeek > 0 && (
                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                            ({row.soldWeek} this
                                                            week)
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {row.last
                                                        ? price(
                                                              row.last.price,
                                                              row.last.currency,
                                                          )
                                                        : '–'}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {row.average !== null &&
                                                    row.last
                                                        ? price(
                                                              row.average,
                                                              row.last.currency,
                                                          )
                                                        : '–'}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    <Change
                                                        value={row.change}
                                                    />
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {row.onSale
                                                        ? `${row.onSale.count} from ${price(row.onSale.lowest, row.onSale.currency)}`
                                                        : '–'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

function Leaders({ title, rows }: { title: string; rows: Row[] }) {
    return (
        <section className="flex flex-col gap-2 rounded-xl border bg-card p-4">
            <h2 className="font-bold">{title}</h2>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nothing sold yet.
                </p>
            ) : (
                <ol className="flex flex-col gap-2">
                    {rows.map((row, i) => (
                        <li
                            key={row.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <span className="w-4 text-right text-muted-foreground">
                                {i + 1}
                            </span>
                            <ItemPreview design={row} size={28} />
                            <span className="min-w-0 flex-1 truncate font-semibold">
                                {row.name}
                            </span>
                            <span className="text-muted-foreground">
                                {row.sold} sold · {row.circulating} about
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
