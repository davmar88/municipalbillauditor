import { Head, Link } from '@inertiajs/react';
import { Globe2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { shortWhen } from '@/lib/game';
import { cn } from '@/lib/utils';
import { visitors } from '@/routes/admin';

type Country = {
    code: string | null;
    name: string;
    flag: string;
    visitors: number;
    views: number;
};

type Recent = {
    id: number;
    at: string;
    userId: number | null;
    who: string | null;
    flag: string;
    country: string;
    /** Something done, not a page opened. */
    action: boolean;
    what: string;
    path: string;
};

type Props = {
    days: number;
    totals: {
        visitors: number;
        views: number;
        actions: number;
        players: number;
        countries: number;
        signups: number;
    };
    perDay: { day: string; visitors: number; views: number }[];
    countries: Country[];
    pages: { what: string; path: string; views: number; visitors: number }[];
    referrers: { site: string; visitors: number }[];
    filteredBy: string | null;
    recent: Recent[];
    /** Whether the country list has been loaded. */
    geoReady: boolean;
};

const PERIODS = [
    [1, 'Today'],
    [7, '7 days'],
    [30, '30 days'],
    [90, '90 days'],
] as const;

const number = (n: number) => n.toLocaleString('en-ZA');

/**
 * The owner's Visitors page: how many people came, from where, what they
 * opened and who did what. A visitor is one device on one day; internet
 * addresses are never kept, only the country they point to.
 */
export default function VisitorsPage({
    days,
    totals,
    perDay,
    countries,
    pages,
    referrers,
    filteredBy,
    recent,
    geoReady,
}: Props) {
    const most = Math.max(1, ...perDay.map((d) => d.visitors));
    const top = Math.max(1, ...countries.map((c) => c.visitors));

    return (
        <>
            <Head title="Visitors" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight">
                            <Globe2 className="size-6" aria-hidden /> Visitors
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            A visitor is one phone or computer on one day.
                            Internet addresses are never kept, only the country
                            they come from. Robots and your own admin pages
                            aren&rsquo;t counted.
                        </p>
                    </div>
                    <nav className="flex gap-1 rounded-lg border bg-card p-1 text-sm">
                        {PERIODS.map(([value, label]) => (
                            <Link
                                key={value}
                                href={visitors({ query: { days: value } })}
                                className={cn(
                                    'rounded-md px-3 py-1.5 font-semibold',
                                    value === days
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                                preserveScroll
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                </header>

                {!geoReady && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm">
                        The country list isn&rsquo;t loaded yet, so countries
                        show as Unknown. It loads on the 3rd of each month, or
                        straight away with{' '}
                        <span className="font-mono">
                            php artisan geo:update
                        </span>
                        .
                    </p>
                )}

                <section className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Stat label="Visitors">{number(totals.visitors)}</Stat>
                    <Stat label="Pages opened">{number(totals.views)}</Stat>
                    <Stat label="Things done">{number(totals.actions)}</Stat>
                    <Stat label="Players signed in">
                        {number(totals.players)}
                    </Stat>
                    <Stat label="Countries">{number(totals.countries)}</Stat>
                    <Stat label="Sign-ups">{number(totals.signups)}</Stat>
                </section>

                {perDay.length > 1 && (
                    <Card title="Visitors each day">
                        <div
                            className="flex h-32 items-end gap-1"
                            role="img"
                            aria-label="Visitors each day"
                        >
                            {perDay.map((d) => (
                                <div
                                    key={d.day}
                                    className="flex-1 rounded-t bg-primary/80"
                                    style={{
                                        height: `${Math.max(3, (d.visitors / most) * 100)}%`,
                                    }}
                                    title={`${d.day}: ${number(d.visitors)} visitors, ${number(d.views)} pages and actions`}
                                />
                            ))}
                        </div>
                        <div className="flex justify-between text-xs text-muted-foreground">
                            <span>{perDay[0].day}</span>
                            <span>{perDay[perDay.length - 1].day}</span>
                        </div>
                    </Card>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card title="Countries">
                        {countries.length === 0 ? (
                            <Empty />
                        ) : (
                            <ul className="flex flex-col gap-2 text-sm">
                                {countries.map((c) => (
                                    <li
                                        key={c.code ?? 'unknown'}
                                        className="flex items-center gap-2"
                                    >
                                        <span aria-hidden className="text-lg">
                                            {c.flag}
                                        </span>
                                        <span className="w-36 truncate font-semibold">
                                            {c.name}
                                        </span>
                                        <span className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                            <span
                                                className="block h-full rounded-full bg-primary"
                                                style={{
                                                    width: `${(c.visitors / top) * 100}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="w-24 text-right tabular-nums">
                                            {number(c.visitors)}{' '}
                                            <span className="text-muted-foreground">
                                                visitors
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card title="Most opened">
                        {pages.length === 0 ? (
                            <Empty />
                        ) : (
                            <ul className="flex flex-col divide-y text-sm">
                                {pages.map((p) => (
                                    <li
                                        key={p.path}
                                        className="flex items-center gap-2 py-1.5"
                                    >
                                        <span className="font-semibold">
                                            {p.what}
                                        </span>
                                        <span className="truncate font-mono text-xs text-muted-foreground">
                                            {p.path}
                                        </span>
                                        <span className="ml-auto tabular-nums">
                                            {number(p.views)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                {referrers.length > 0 && (
                    <Card title="Came from">
                        <ul className="flex flex-wrap gap-2 text-sm">
                            {referrers.map((r) => (
                                <li
                                    key={r.site}
                                    className="rounded-full border px-3 py-1"
                                >
                                    {r.site}{' '}
                                    <span className="text-muted-foreground">
                                        {number(r.visitors)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                <Card
                    title={
                        filteredBy
                            ? `What ${filteredBy} did`
                            : 'Who did what, latest first'
                    }
                    actions={
                        filteredBy && (
                            <Link
                                href={visitors({ query: { days } })}
                                className="text-sm font-semibold text-primary"
                            >
                                Show everyone
                            </Link>
                        )
                    }
                >
                    {recent.length === 0 ? (
                        <Empty />
                    ) : (
                        <ul className="flex flex-col divide-y text-sm">
                            {recent.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-0.5 py-1.5"
                                >
                                    <span className="w-16 shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {shortWhen(r.at)}
                                    </span>
                                    <span
                                        title={r.country}
                                        aria-label={r.country}
                                    >
                                        {r.flag}
                                    </span>
                                    {r.userId !== null ? (
                                        <Link
                                            href={visitors({
                                                query: { days, user: r.userId },
                                            })}
                                            className="font-semibold text-primary"
                                        >
                                            {r.who}
                                        </Link>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {r.country === 'Unknown'
                                                ? 'A visitor'
                                                : `A visitor from ${r.country}`}
                                        </span>
                                    )}
                                    <span
                                        className={cn(
                                            r.action && 'font-semibold',
                                        )}
                                    >
                                        {r.what}
                                    </span>
                                    <span className="truncate font-mono text-xs text-muted-foreground">
                                        {r.path}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <p className="text-xs text-muted-foreground">
                    Visits are kept for 90 days. Countries from{' '}
                    <a
                        href="https://db-ip.com"
                        className="underline"
                        target="_blank"
                        rel="noreferrer"
                    >
                        IP Geolocation by DB-IP
                    </a>
                    .
                </p>
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
            <p className="mt-1 text-2xl font-extrabold tabular-nums">
                {children}
            </p>
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
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-bold">{title}</h2>
                {actions}
            </div>
            {children}
        </section>
    );
}

function Empty() {
    return (
        <p className="text-sm text-muted-foreground">No visits in this time.</p>
    );
}
