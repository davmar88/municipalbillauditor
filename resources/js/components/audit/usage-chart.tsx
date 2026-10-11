import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { show } from '@/routes/bills';
import type { UsagePoint, UsageSeries } from '@/types';

const PLOT_HEIGHT = 120;
const quantity = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });

function describe(point: UsagePoint, unit: string): string {
    const parts = [`${point.label}: ${quantity.format(point.value)} ${unit}`];

    parts.push(point.estimated ? 'estimated reading' : 'actual reading');

    if (point.flagged) {
        parts.push('flagged');
    }

    return parts.join(', ');
}

/**
 * A year of use for one meter. Months are recessive grey; the flagged month
 * is the one orange bar. Estimated months are a pale wash with an "E", and
 * the dashed line is the average of actual readings.
 */
export function UsageChart({ series }: { series: UsageSeries }) {
    const [view, setView] = useState<'chart' | 'table'>('chart');
    const [active, setActive] = useState<number | null>(null);
    const { points, unit, average } = series;
    const top = Math.max(...points.map((p) => p.value), average ?? 0) * 1.2;
    const scale = (value: number) => (top > 0 ? (value / top) * 100 : 0);
    const columns = points.length;

    return (
        <figure className="flex min-w-0 flex-col gap-2">
            <div className="flex items-center justify-between gap-3">
                <figcaption className="text-sm text-foreground/80">
                    <b className="font-semibold text-foreground">
                        {series.label}
                    </b>{' '}
                    · {unit} per month
                    {average !== null && (
                        <> · average {quantity.format(average)}</>
                    )}
                </figcaption>
                <button
                    type="button"
                    aria-pressed={view === 'table'}
                    onClick={() =>
                        setView(view === 'chart' ? 'table' : 'chart')
                    }
                    className="min-h-11 shrink-0 px-1 text-sm font-semibold text-primary underline underline-offset-[3px]"
                >
                    {view === 'chart' ? 'Show table' : 'Show chart'}
                </button>
            </div>

            {view === 'chart' ? (
                <div className="flex flex-col gap-1.5">
                    <div
                        className="relative mr-14"
                        style={{ height: PLOT_HEIGHT }}
                    >
                        <div
                            className="grid h-full items-end gap-1.5 border-b border-chart-ink/40"
                            style={{
                                gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                            }}
                        >
                            {points.map((point, index) => (
                                <Bar
                                    key={point.billId}
                                    point={point}
                                    unit={unit}
                                    height={scale(point.value)}
                                    onActive={(on) =>
                                        setActive(on ? index : null)
                                    }
                                />
                            ))}
                        </div>

                        {average !== null && (
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -right-14 left-0 flex items-center"
                                style={{
                                    bottom: `calc(${scale(average)}% - 1px)`,
                                }}
                            >
                                <span className="flex-1 border-t-[1.5px] border-dashed border-chart-ink/70" />
                                <span className="figures w-14 pl-1.5 text-[11px] leading-none text-muted-foreground">
                                    avg {quantity.format(average)}
                                </span>
                            </div>
                        )}

                        {active !== null && (
                            <Tooltip
                                point={points[active]}
                                unit={unit}
                                index={active}
                                columns={columns}
                                bottom={scale(points[active].value)}
                            />
                        )}
                    </div>

                    <div
                        aria-hidden
                        className="figures mr-14 grid gap-1.5 text-center text-[11px] leading-[14px] text-muted-foreground"
                        style={{
                            gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                        }}
                    >
                        {points.map((point) => (
                            <span
                                key={point.billId}
                                className={cn(
                                    point.current &&
                                        'font-semibold text-foreground',
                                )}
                            >
                                <span className="sm:hidden">
                                    {point.month.charAt(0)}
                                </span>
                                <span className="hidden sm:inline">
                                    {point.month}
                                </span>
                            </span>
                        ))}
                    </div>
                </div>
            ) : (
                <table className="w-full text-sm">
                    <caption className="sr-only">
                        {series.label} use per month
                    </caption>
                    <thead>
                        <tr className="border-b text-left text-muted-foreground">
                            <th scope="col" className="py-1.5 font-medium">
                                Bill
                            </th>
                            <th
                                scope="col"
                                className="py-1.5 text-right font-medium"
                            >
                                Used ({unit})
                            </th>
                            <th scope="col" className="py-1.5 pl-4 font-medium">
                                Reading
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {points.map((point) => (
                            <tr
                                key={point.billId}
                                className="border-b border-dotted last:border-0"
                            >
                                <th
                                    scope="row"
                                    className="py-1.5 text-left font-normal"
                                >
                                    {point.label}
                                    {point.current && ' (this bill)'}
                                </th>
                                <td className="figures py-1.5 text-right">
                                    {quantity.format(point.value)}
                                </td>
                                <td className="py-1.5 pl-4">
                                    {point.estimated ? 'Estimated' : 'Actual'}
                                    {point.flagged && (
                                        <span className="font-semibold text-flag-ink">
                                            {' '}
                                            · flagged
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </figure>
    );
}

function Bar({
    point,
    unit,
    height,
    onActive,
}: {
    point: UsagePoint;
    unit: string;
    height: number;
    onActive: (on: boolean) => void;
}) {
    // Direct labels only where the eye should go: the flagged month's value,
    // and an "E" on every estimate.
    const label =
        `${point.flagged ? quantity.format(point.value) : ''}${point.estimated ? ' E' : ''}`.trim();
    const events = {
        onMouseEnter: () => onActive(true),
        onMouseLeave: () => onActive(false),
        onFocus: () => onActive(true),
        onBlur: () => onActive(false),
    };
    const body = (
        <>
            {label && (
                <span
                    className={cn(
                        'figures mb-1 text-[11px] leading-none font-semibold',
                        point.flagged
                            ? 'text-flag-ink'
                            : 'text-muted-foreground',
                    )}
                >
                    {label}
                </span>
            )}
            <span
                className={cn(
                    'w-full max-w-9 rounded-t-[4px]',
                    point.flagged
                        ? point.estimated
                            ? 'border-[1.5px] border-b-0 border-chart-accent bg-chart-accent/30'
                            : 'bg-chart-accent'
                        : point.estimated
                          ? 'border-[1.5px] border-b-0 border-chart-muted bg-chart-muted/25'
                          : 'bg-chart-muted',
                )}
                style={{ height: `${height}%` }}
            />
        </>
    );
    const className =
        'flex h-full min-w-0 flex-col items-center justify-end outline-none focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring';

    if (point.current) {
        return (
            <span
                role="img"
                aria-label={`${describe(point, unit)} (this bill)`}
                className={className}
                {...events}
            >
                {body}
            </span>
        );
    }

    return (
        <Link
            href={show(point.billId)}
            aria-label={`${describe(point, unit)}. Open this bill.`}
            className={className}
            {...events}
        >
            {body}
        </Link>
    );
}

function Tooltip({
    point,
    unit,
    index,
    columns,
    bottom,
}: {
    point: UsagePoint;
    unit: string;
    index: number;
    columns: number;
    bottom: number;
}) {
    const centre = ((index + 0.5) / columns) * 100;
    const align =
        index < columns / 4
            ? 'left'
            : index >= (columns * 3) / 4
              ? 'right'
              : 'centre';

    return (
        <div
            role="presentation"
            className="pointer-events-none absolute z-10 w-max max-w-48 rounded-md bg-foreground px-2.5 py-1.5 text-xs leading-4 text-background shadow-md"
            style={{
                bottom: `calc(${bottom}% + 22px)`,
                left: `${centre}%`,
                transform:
                    align === 'left'
                        ? 'translateX(-12px)'
                        : align === 'right'
                          ? 'translateX(calc(-100% + 12px))'
                          : 'translateX(-50%)',
            }}
        >
            <span className="block font-semibold">{point.label}</span>
            <span className="figures block">
                {quantity.format(point.value)} {unit}
            </span>
            <span className="block opacity-80">
                {point.estimated ? 'Estimated reading' : 'Actual reading'}
                {point.flagged && ' · flagged'}
            </span>
        </div>
    );
}

/** The key for every usage chart on the page. */
export function UsageKey() {
    return (
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-foreground/80">
            <span className="flex items-center gap-1.5">
                <span className="size-3 rounded-[2px] bg-chart-muted" />
                Actual reading
            </span>
            <span className="flex items-center gap-1.5">
                <span className="size-3 rounded-[2px] border-[1.5px] border-chart-muted bg-chart-muted/25" />
                Estimated (E)
            </span>
            <span className="flex items-center gap-1.5">
                <span className="size-3 rounded-[2px] bg-chart-accent" />
                Flagged
            </span>
            <span className="flex items-center gap-1.5">
                <span className="w-4 border-t-[1.5px] border-dashed border-chart-ink/70" />
                Average of actual readings
            </span>
        </div>
    );
}
