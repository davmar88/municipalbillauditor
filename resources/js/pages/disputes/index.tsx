import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Chip } from '@/components/audit/chip';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { Button } from '@/components/ui/button';
import { index as bills } from '@/routes/bills';
import { create, index, show } from '@/routes/disputes';
import type { BillSummary, DisputeSummary } from '@/types';

type Props = {
    disputes: DisputeSummary[];
    drafts: BillSummary[];
    isBusiness: boolean;
};

export default function DisputesIndex({ disputes, drafts, isBusiness }: Props) {
    const open = disputes.filter((d) => d.isOpen);
    const closed = disputes.filter((d) => !d.isOpen);
    const noun = isBusiness ? 'claim' : 'dispute';

    return (
        <>
            <Head title={isBusiness ? 'Claims' : 'Disputes'} />
            <PageBody>
                <PageHeader
                    title={isBusiness ? 'Claims' : 'Disputes'}
                    description={
                        isBusiness
                            ? 'Every claim we are running for you, and what it has won so far.'
                            : 'Every dispute, its deadline, and what happens next.'
                    }
                />

                {drafts.length > 0 && (
                    <section
                        aria-labelledby="ready-h"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="ready-h" className="text-lg font-bold">
                            Ready to {isBusiness ? 'claim' : 'dispute'}
                        </h2>
                        <ul className="grid gap-3 md:grid-cols-2">
                            {drafts.map((bill) => (
                                <li
                                    key={bill.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4"
                                >
                                    <span className="flex flex-col">
                                        <span className="font-semibold">
                                            {bill.month}
                                            {bill.property &&
                                                ` · ${bill.property.label}`}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {bill.flags === 1
                                                ? '1 flag'
                                                : `${bill.flags} flags`}
                                            {bill.upToTotal &&
                                                `, up to ${bill.upToTotal}`}
                                        </span>
                                    </span>
                                    <Button asChild>
                                        <Link href={create(bill.id)}>
                                            Draft {noun}
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section
                    aria-labelledby="open-h"
                    className="flex flex-col gap-3"
                >
                    <h2 id="open-h" className="text-lg font-bold">
                        Open
                    </h2>
                    {open.length === 0 ? (
                        <p className="rounded-lg border border-dashed bg-card p-5 text-muted-foreground">
                            No open {noun}s.{' '}
                            {drafts.length === 0 && (
                                <>
                                    When a checked bill has something to
                                    dispute, it shows up here.{' '}
                                    <Link
                                        href={bills()}
                                        className="font-semibold text-primary underline underline-offset-[3px]"
                                    >
                                        Check a bill
                                    </Link>
                                </>
                            )}
                        </p>
                    ) : (
                        <DisputeList disputes={open} />
                    )}
                </section>

                {closed.length > 0 && (
                    <section
                        aria-labelledby="closed-h"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="closed-h" className="text-lg font-bold">
                            Resolved and closed
                        </h2>
                        <DisputeList disputes={closed} />
                    </section>
                )}
            </PageBody>
        </>
    );
}

DisputesIndex.layout = {
    breadcrumbs: [{ title: 'Disputes', href: index() }],
};

function DisputeList({ disputes }: { disputes: DisputeSummary[] }) {
    return (
        <ul className="flex flex-col divide-y overflow-hidden rounded-lg border bg-card">
            {disputes.map((dispute) => (
                <li key={dispute.id}>
                    <Link
                        href={show(dispute.id)}
                        prefetch
                        className="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-accent/60"
                    >
                        <span className="flex min-w-48 flex-1 flex-col">
                            <span className="font-semibold">
                                {dispute.billMonth} · {dispute.property}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {dispute.issues === 1
                                    ? '1 issue'
                                    : `${dispute.issues} issues`}
                                ,{' '}
                                <span className="figures">
                                    {dispute.disputedTotal}
                                </span>
                                {dispute.reference &&
                                    ` · Ref ${dispute.reference}`}
                            </span>
                        </span>
                        <span className="flex flex-col items-start gap-1 sm:items-end">
                            <Chip
                                tone={
                                    dispute.needsAction
                                        ? 'flag-outline'
                                        : dispute.isOpen
                                          ? 'action'
                                          : 'neutral'
                                }
                            >
                                {dispute.stageLabel}
                            </Chip>
                            {dispute.nextDate && (
                                <span className="text-sm text-muted-foreground">
                                    Next: {dispute.nextDate}
                                    {dispute.daysLeft !== null &&
                                        dispute.daysLeft >= 0 &&
                                        ` · ${dispute.daysLeft} days`}
                                </span>
                            )}
                        </span>
                        <ChevronRight
                            className="hidden size-4 shrink-0 text-muted-foreground sm:block"
                            aria-hidden
                        />
                    </Link>
                </li>
            ))}
        </ul>
    );
}
