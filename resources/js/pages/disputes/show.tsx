import { Form, Head, Link, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { Chip } from '@/components/audit/chip';
import { FlagNumber } from '@/components/audit/flag-number';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { Timeline } from '@/components/audit/timeline';
import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { localToday } from '@/lib/dates';
import { show as showBill } from '@/routes/bills';
import { index } from '@/routes/disputes';
import { store as storeEvent } from '@/routes/disputes/events';
import type { DisputeAction, DisputeDetail } from '@/types';
import { AddressText } from '@/components/address-text';

type Props = {
    dispute: DisputeDetail;
    councillorLetter: { subject: string; body: string } | null;
    isBusiness: boolean;
};

export default function DisputeShow({
    dispute,
    councillorLetter,
    isBusiness,
}: Props) {
    const { organisation } = usePage().props;
    const title = isBusiness
        ? (dispute.findings[0]?.title ?? 'Claim')
        : `Dispute · ${dispute.billMonth} bill`;

    return (
        <>
            <Head title={title} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <>
                            {dispute.property} · {dispute.metro}
                        </>
                    }
                    title={title}
                    description={
                        <>
                            {isBusiness && dispute.backRefundClaimed
                                ? 'Claiming '
                                : 'Disputing '}
                            <span className="figures text-sm">
                                {isBusiness && dispute.backRefundClaimed
                                    ? dispute.backRefundClaimed
                                    : dispute.disputedTotal}
                            </span>
                            {isBusiness && dispute.backRefundClaimed && ' back'}
                            {dispute.lodgedOn &&
                                ` · Lodged ${dispute.lodgedOn}`}
                        </>
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={showBill(dispute.billId)}>
                                <FileText aria-hidden />
                                The bill
                            </Link>
                        </Button>
                    }
                />

                {isBusiness && dispute.fees ? (
                    <ClaimFigures dispute={dispute} />
                ) : (
                    <Status dispute={dispute} />
                )}

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                    <div className="flex min-w-0 flex-col gap-6">
                        <section
                            aria-labelledby="timeline-h"
                            className="flex flex-col gap-4 rounded-lg border bg-card p-5"
                        >
                            <h2 id="timeline-h" className="text-lg font-bold">
                                {isBusiness
                                    ? 'Where the claim stands'
                                    : 'What has happened'}
                            </h2>
                            <Timeline steps={dispute.timeline} />
                        </section>

                        {dispute.fees && <FeeTable fees={dispute.fees} />}

                        {councillorLetter && (
                            <CouncillorLetter
                                letter={councillorLetter}
                                managed={dispute.mode !== 'self'}
                            />
                        )}

                        <details className="group rounded-lg border bg-card">
                            <summary className="flex min-h-12 cursor-pointer items-center px-5 font-semibold">
                                The letter we sent
                            </summary>
                            <div className="flex flex-col gap-3 border-t px-5 py-4">
                                <p className="font-semibold">
                                    {dispute.letterSubject}
                                </p>
                                <div className="text-sm leading-6 whitespace-pre-wrap">
                                    {dispute.letterBody}
                                </div>
                            </div>
                        </details>
                    </div>

                    <div className="flex min-w-0 flex-col gap-6">
                        {dispute.reference && (
                            <section className="flex flex-col gap-1 rounded-lg border bg-card p-5">
                                <h2 className="text-sm text-muted-foreground">
                                    Municipal reference
                                </h2>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="figures text-xl font-semibold">
                                        {dispute.reference}
                                    </span>
                                    <CopyButton value={dispute.reference} />
                                </div>
                            </section>
                        )}

                        {dispute.actions.length > 0 && (
                            <RecordEvent
                                disputeId={dispute.id}
                                actions={dispute.actions}
                            />
                        )}

                        {!isBusiness &&
                            dispute.undisputedAmount &&
                            dispute.isOpen && (
                                <section className="flex flex-col gap-1 rounded-lg bg-action-soft p-5 text-action-ink">
                                    <h2 className="font-bold">
                                        Keep paying the rest
                                    </h2>
                                    <p className="text-sm">
                                        Pay{' '}
                                        <span className="figures font-semibold">
                                            {dispute.undisputedAmount}
                                        </span>
                                        , the undisputed part of this bill, so
                                        your account stays in good standing.
                                    </p>
                                </section>
                            )}

                        <section className="flex flex-col gap-3 rounded-lg border bg-card p-5">
                            <h2 className="font-bold">
                                {isBusiness ? 'Claimed' : 'Disputed'}
                            </h2>
                            <ul className="flex flex-col gap-2.5">
                                {dispute.findings.map((finding) => (
                                    <li
                                        key={finding.number}
                                        className="flex items-start gap-2.5 text-sm"
                                    >
                                        <FlagNumber number={finding.number} />
                                        <span className="flex-1">
                                            {finding.title}
                                        </span>
                                        {finding.amount && (
                                            <span className="figures">
                                                {finding.amount}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>

                        {dispute.tariff && (
                            <TariffCard tariff={dispute.tariff} />
                        )}

                        {dispute.events.length > 0 && (
                            <section className="flex flex-col gap-3 rounded-lg border bg-card p-5">
                                <h2 className="font-bold">Notes and updates</h2>
                                <ul className="flex flex-col gap-3 text-sm">
                                    {dispute.events.map((event) => (
                                        <li
                                            key={event.id}
                                            className="flex flex-col"
                                        >
                                            <span className="flex justify-between gap-2">
                                                <span className="font-semibold">
                                                    {event.label}
                                                </span>
                                                <span className="figures text-xs text-muted-foreground uppercase">
                                                    {event.on}
                                                </span>
                                            </span>
                                            {event.note && (
                                                <span className="text-muted-foreground">
                                                    {event.note}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {dispute.invoices.length > 0 && (
                            <section className="flex flex-col gap-3 rounded-lg border bg-card p-5">
                                <h2 className="font-bold">Invoices</h2>
                                <ul className="flex flex-col gap-2 text-sm">
                                    {dispute.invoices.map((invoice) => (
                                        <li
                                            key={invoice.id}
                                            className="flex justify-between gap-3"
                                        >
                                            <span>{invoice.description}</span>
                                            <span className="figures">
                                                {invoice.amount}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {organisation && (
                            <p className="text-sm text-muted-foreground">
                                Got a reply? Forward it to{' '}
                                <span className="figures [overflow-wrap:anywhere] text-foreground">
                                    <AddressText
                                        address={organisation.forwardingAddress}
                                    />
                                </span>
                            </p>
                        )}
                    </div>
                </div>
            </PageBody>
        </>
    );
}

DisputeShow.layout = {
    breadcrumbs: [{ title: 'Disputes', href: index() }],
};

function Status({ dispute }: { dispute: DisputeDetail }) {
    const { status } = dispute;

    return (
        <section
            aria-label="Where it stands"
            className="flex flex-col gap-4 rounded-lg bg-panel p-5 text-panel-foreground sm:p-6"
        >
            <Chip tone="action" className="self-start">
                {status.headline}
            </Chip>
            {status.daysLeft !== null && status.countdownLabel && (
                <p className="flex flex-wrap items-baseline gap-x-3">
                    <span className="text-5xl leading-none font-bold">
                        {status.daysLeft}
                    </span>
                    <span className="text-panel-muted">
                        {status.countdownLabel}
                    </span>
                </p>
            )}
            {status.progress !== null && (
                <div
                    role="progressbar"
                    aria-label="Time used of the reply window"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={Math.round(status.progress * 100)}
                    className="h-2 overflow-hidden rounded-full bg-white/15"
                >
                    <div
                        className="h-full rounded-full bg-[#7ca2f5]"
                        style={{
                            width: `${Math.min(100, status.progress * 100)}%`,
                        }}
                    />
                </div>
            )}
            {status.note && <p className="text-panel-muted">{status.note}</p>}
        </section>
    );
}

function ClaimFigures({ dispute }: { dispute: DisputeDetail }) {
    const fees = dispute.fees!;
    const credited = dispute.creditAmount !== null;

    return (
        <section
            aria-label="The claim in figures"
            className="grid gap-4 md:grid-cols-3"
        >
            <div className="flex flex-col gap-1 rounded-lg border bg-card p-5">
                <span className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                    Back-refund claimed
                    <Chip tone={credited ? 'action' : 'flag-outline'}>
                        {credited ? 'Credited' : 'Waiting for credit'}
                    </Chip>
                </span>
                <span className="figures text-2xl font-semibold">
                    {fees.backRefund}
                </span>
            </div>
            <div className="flex flex-col gap-1 rounded-lg border bg-card p-5">
                <span className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                    Saving since the fix
                    {dispute.tariff?.after && (
                        <Chip tone="action">Confirmed</Chip>
                    )}
                </span>
                <span className="figures text-2xl font-semibold">
                    {fees.monthlySaving ? `${fees.monthlySaving} / month` : '–'}
                </span>
            </div>
            <div className="flex flex-col gap-1 rounded-lg border bg-card p-5">
                <span className="text-sm text-muted-foreground">
                    Our success fee
                </span>
                <span className="text-2xl font-semibold">{fees.rate}</span>
                <span className="text-sm text-muted-foreground">
                    Only on money that reaches your account
                </span>
            </div>
        </section>
    );
}

function FeeTable({ fees }: { fees: NonNullable<DisputeDetail['fees']> }) {
    return (
        <section
            aria-labelledby="fees-h"
            className="flex flex-col gap-3 rounded-lg border bg-card p-5"
        >
            <h2 id="fees-h" className="text-lg font-bold">
                How your fee works
            </h2>
            <p className="text-sm text-muted-foreground">
                {fees.rate} of the back-refund plus the first{' '}
                {fees.savingMonths} months of savings. Nothing is due until the
                money shows on your municipal account.
            </p>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[480px] text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                            <th scope="col" className="py-2 font-medium">
                                WON
                            </th>
                            <th
                                scope="col"
                                className="py-2 pl-4 text-right font-medium whitespace-nowrap"
                            >
                                AMOUNT
                            </th>
                            <th
                                scope="col"
                                className="py-2 pl-4 text-right font-medium whitespace-nowrap"
                            >
                                FEE AT {fees.rate}
                            </th>
                            <th scope="col" className="py-2 pl-4 font-medium">
                                INVOICED
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr className="border-b border-dotted">
                            <th
                                scope="row"
                                className="py-2.5 text-left font-normal"
                            >
                                Back-refund
                            </th>
                            <td className="figures py-2.5 pl-4 text-right whitespace-nowrap">
                                {fees.backRefund}
                            </td>
                            <td className="figures py-2.5 pl-4 text-right whitespace-nowrap">
                                {fees.backRefundFee}
                            </td>
                            <td className="py-2.5 pl-4 text-muted-foreground">
                                {fees.backRefundInvoiced
                                    ? 'Invoiced'
                                    : 'Once, when the credit appears'}
                            </td>
                        </tr>
                        <tr className="border-b">
                            <th
                                scope="row"
                                className="py-2.5 text-left font-normal"
                            >
                                Savings, first {fees.savingMonths} months
                                {fees.monthlySaving && (
                                    <span className="figures block text-xs text-muted-foreground">
                                        {fees.monthlySaving} ×{' '}
                                        {fees.savingMonths}
                                    </span>
                                )}
                            </th>
                            <td className="figures py-2.5 pl-4 text-right whitespace-nowrap">
                                {fees.savingsTotal}
                            </td>
                            <td className="figures py-2.5 pl-4 text-right whitespace-nowrap">
                                {fees.savingsFeeTotal}
                            </td>
                            <td className="py-2.5 pl-4 text-muted-foreground">
                                {fees.monthlySavingFee} a month, as each saving
                                shows · {fees.savingsInvoiced} of{' '}
                                {fees.savingMonths} invoiced
                            </td>
                        </tr>
                        <tr className="font-semibold">
                            <th scope="row" className="py-2.5 text-left">
                                Total
                            </th>
                            <td className="figures py-2.5 pl-4 text-right whitespace-nowrap">
                                {fees.totalWon}
                            </td>
                            <td className="figures py-2.5 pl-4 text-right whitespace-nowrap">
                                {fees.totalFee}
                            </td>
                            <td className="figures py-2.5 pl-4">
                                {fees.invoicedSoFar} so far
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function TariffCard({
    tariff,
}: {
    tariff: NonNullable<DisputeDetail['tariff']>;
}) {
    return (
        <section className="flex flex-col gap-3 rounded-lg border bg-card p-5">
            <h2 className="font-bold">Tariff on your bills</h2>
            <dl className="flex flex-col gap-3 text-sm">
                <div className="flex justify-between gap-3">
                    <dt>
                        {tariff.before.month}
                        <span className="block font-semibold text-flag-ink">
                            {tariff.before.label}
                        </span>
                    </dt>
                    <dd className="figures">{tariff.before.total}</dd>
                </div>
                {tariff.after ? (
                    <div className="flex justify-between gap-3">
                        <dt>
                            {tariff.after.month}
                            <span className="block font-semibold text-primary">
                                {tariff.after.label}
                            </span>
                        </dt>
                        <dd className="figures">{tariff.after.total}</dd>
                    </div>
                ) : (
                    <p className="text-muted-foreground">
                        We're watching for a bill on{' '}
                        {tariff.expected ?? 'the right tariff'}.
                    </p>
                )}
            </dl>
            {tariff.monthlySaving && (
                <p className="text-sm">
                    Difference:{' '}
                    <span className="figures font-semibold">
                        {tariff.monthlySaving}
                    </span>{' '}
                    a month.
                </p>
            )}
        </section>
    );
}

function CouncillorLetter({
    letter,
    managed,
}: {
    letter: { subject: string; body: string };
    managed: boolean;
}) {
    return (
        <section
            aria-labelledby="councillor-h"
            className="flex flex-col gap-3 rounded-lg border border-flag/40 bg-card p-5"
        >
            <h2 id="councillor-h" className="text-lg font-bold">
                {managed
                    ? 'The letter to your ward councillor'
                    : 'Letter to your ward councillor'}
            </h2>
            <p className="text-sm text-muted-foreground">
                {managed
                    ? "The municipality hasn't replied in time, so our team is sending this to your ward councillor. You don't need to do anything."
                    : "The municipality hasn't replied in time. Send this to your ward councillor, then record it below."}
            </p>
            <div className="rounded-md bg-muted p-4 text-sm">
                <p className="font-semibold">{letter.subject}</p>
                <div className="mt-2 leading-6 whitespace-pre-wrap">
                    {letter.body}
                </div>
            </div>
            <CopyButton
                value={`${letter.subject}\n\n${letter.body}`}
                label="Copy the letter"
                className="h-11 self-start border"
            />
        </section>
    );
}

function RecordEvent({
    disputeId,
    actions,
}: {
    disputeId: number;
    actions: DisputeAction[];
}) {
    const [kind, setKind] = useState(actions[0].kind);
    const action = actions.find((a) => a.kind === kind) ?? actions[0];
    const today = localToday();

    return (
        <section
            aria-labelledby="record-h"
            className="flex flex-col gap-3 rounded-lg border bg-card p-5"
        >
            <h2 id="record-h" className="font-bold">
                Record what happened
            </h2>
            <Form
                {...storeEvent.form(disputeId)}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="flex flex-col gap-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1.5">
                            <Label htmlFor="kind">What happened?</Label>
                            <NativeSelect
                                id="kind"
                                name="kind"
                                value={kind}
                                onChange={(e) => setKind(e.target.value)}
                            >
                                {actions.map((a) => (
                                    <option key={a.kind} value={a.kind}>
                                        {a.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.kind} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="happened_on">On</Label>
                            <Input
                                id="happened_on"
                                name="happened_on"
                                type="date"
                                max={today}
                                defaultValue={today}
                                required
                            />
                            <InputError message={errors.happened_on} />
                        </div>
                        {action.fields.includes('reference') && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="reference">
                                    Reference number
                                </Label>
                                <Input
                                    id="reference"
                                    name="reference"
                                    className="font-mono"
                                    required
                                />
                                <InputError message={errors.reference} />
                            </div>
                        )}
                        {action.fields.includes('amount') && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="amount">
                                    Amount credited (R)
                                </Label>
                                <Input
                                    id="amount"
                                    name="amount"
                                    inputMode="decimal"
                                    className="font-mono"
                                    required
                                />
                                <InputError message={errors.amount} />
                            </div>
                        )}
                        {action.fields.includes('note') && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="note">Note (optional)</Label>
                                <Textarea id="note" name="note" rows={3} />
                                <InputError message={errors.note} />
                            </div>
                        )}
                        <Button
                            type="submit"
                            className="self-start"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Save
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
