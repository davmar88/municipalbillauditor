import { Form, Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Chip } from '@/components/audit/chip';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { localToday } from '@/lib/dates';
import { index, reference } from '@/routes/staff';
import { file } from '@/routes/staff/bills';
import { lodge } from '@/routes/staff/disputes';
import { store as recordEvent } from '@/routes/staff/disputes/events';
import type { DisputeSummary, Option } from '@/types';

type StaffDispute = DisputeSummary & {
    organisation: string;
    letterSubject: string;
    letterBody: string;
    billingEmail: string | null;
    billUrl: string | null;
    councillorLetter?: { subject: string; body: string } | null;
};

type Props = {
    toLodge: StaffDispute[];
    needsEscalation: StaffDispute[];
    eventKinds: Option[];
    bills: {
        id: number;
        organisation: string;
        status: string;
        note: string | null;
        received: string;
    }[];
};

export default function StaffIndex({
    toLodge,
    needsEscalation,
    eventKinds,
    bills,
}: Props) {
    return (
        <>
            <Head title="Work queue" />
            <PageBody>
                <PageHeader
                    title="Work queue"
                    description="Managed disputes to lodge, disputes that need the next step, and bills waiting for their details."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={reference()}>Reference data</Link>
                        </Button>
                    }
                />

                <Queue
                    title="To lodge"
                    empty="Nothing waiting to be lodged."
                    count={toLodge.length}
                >
                    {toLodge.map((dispute) => (
                        <DisputeItem key={dispute.id} dispute={dispute}>
                            <Form
                                {...lodge.form(dispute.id)}
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`lodged_on_${dispute.id}`}
                                            >
                                                Lodged on
                                            </Label>
                                            <Input
                                                id={`lodged_on_${dispute.id}`}
                                                name="lodged_on"
                                                type="date"
                                                max={localToday()}
                                                defaultValue={localToday()}
                                                required
                                            />
                                            <InputError
                                                message={errors.lodged_on}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`reference_${dispute.id}`}
                                            >
                                                Reference (if given)
                                            </Label>
                                            <Input
                                                id={`reference_${dispute.id}`}
                                                name="reference"
                                                className="font-mono"
                                            />
                                            <InputError
                                                message={errors.reference}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Mark as lodged
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </DisputeItem>
                    ))}
                </Queue>

                <Queue
                    title="Needs the next step"
                    empty="No managed dispute is overdue."
                    count={needsEscalation.length}
                >
                    {needsEscalation.map((dispute) => (
                        <DisputeItem key={dispute.id} dispute={dispute}>
                            <Form
                                {...recordEvent.form(dispute.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`kind_${dispute.id}`}
                                            >
                                                What happened
                                            </Label>
                                            <NativeSelect
                                                id={`kind_${dispute.id}`}
                                                name="kind"
                                                defaultValue="escalated_councillor"
                                            >
                                                {eventKinds.map((kind) => (
                                                    <option
                                                        key={kind.value}
                                                        value={kind.value}
                                                    >
                                                        {kind.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <InputError message={errors.kind} />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`happened_on_${dispute.id}`}
                                            >
                                                On
                                            </Label>
                                            <Input
                                                id={`happened_on_${dispute.id}`}
                                                name="happened_on"
                                                type="date"
                                                max={localToday()}
                                                defaultValue={localToday()}
                                                required
                                            />
                                            <InputError
                                                message={errors.happened_on}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`ref_${dispute.id}`}
                                            >
                                                Reference
                                            </Label>
                                            <Input
                                                id={`ref_${dispute.id}`}
                                                name="reference"
                                                className="font-mono"
                                            />
                                            <InputError
                                                message={errors.reference}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`amount_${dispute.id}`}
                                            >
                                                Amount credited (R)
                                            </Label>
                                            <Input
                                                id={`amount_${dispute.id}`}
                                                name="amount"
                                                inputMode="decimal"
                                                className="font-mono"
                                            />
                                            <InputError
                                                message={errors.amount}
                                            />
                                        </div>
                                        <div className="grid gap-1.5 sm:col-span-2">
                                            <Label
                                                htmlFor={`note_${dispute.id}`}
                                            >
                                                Note
                                            </Label>
                                            <Input
                                                id={`note_${dispute.id}`}
                                                name="note"
                                            />
                                            <InputError message={errors.note} />
                                        </div>
                                        <Button
                                            type="submit"
                                            className="justify-self-start"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Record
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </DisputeItem>
                    ))}
                </Queue>

                <Queue
                    title="Bills waiting for details"
                    empty="Every bill has been read."
                    count={bills.length}
                >
                    {bills.map((bill) => (
                        <li
                            key={bill.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4"
                        >
                            <span className="flex flex-col">
                                <span className="font-semibold">
                                    {bill.organisation}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {bill.status} · received {bill.received}
                                    {bill.note && ` · ${bill.note}`}
                                </span>
                            </span>
                            <Button variant="outline" asChild>
                                <a
                                    href={file(bill.id).url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Download aria-hidden />
                                    Open the bill
                                </a>
                            </Button>
                        </li>
                    ))}
                </Queue>
            </PageBody>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'Work queue', href: index() }],
};

function Queue({
    title,
    empty,
    count,
    children,
}: {
    title: string;
    empty: string;
    count: number;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="flex items-center gap-2 text-lg font-bold">
                {title}
                <span className="figures rounded-full bg-muted px-2 text-sm font-normal">
                    {count}
                </span>
            </h2>
            {count === 0 ? (
                <p className="text-muted-foreground">{empty}</p>
            ) : (
                <ul className="flex flex-col gap-3">{children}</ul>
            )}
        </section>
    );
}

function DisputeItem({
    dispute,
    children,
}: {
    dispute: StaffDispute;
    children: React.ReactNode;
}) {
    return (
        <li className="flex flex-col gap-4 rounded-lg border bg-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <span className="flex flex-col">
                    <span className="font-semibold">
                        {dispute.organisation} · {dispute.property}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        {dispute.billMonth} bill ·{' '}
                        <span className="figures">{dispute.disputedTotal}</span>
                        {dispute.reference && ` · Ref ${dispute.reference}`}
                    </span>
                </span>
                <Chip tone={dispute.needsAction ? 'flag-outline' : 'action'}>
                    {dispute.stageLabel}
                </Chip>
            </div>
            <details className="rounded-md bg-muted">
                <summary className="flex min-h-11 cursor-pointer items-center px-4 text-sm font-semibold">
                    The letter
                    {dispute.billingEmail && ` · to ${dispute.billingEmail}`}
                </summary>
                <div className="flex flex-col gap-2 border-t px-4 py-3 text-sm">
                    <p className="font-semibold">{dispute.letterSubject}</p>
                    <div className="leading-6 whitespace-pre-wrap">
                        {dispute.letterBody}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <CopyButton
                            value={`${dispute.letterSubject}\n\n${dispute.letterBody}`}
                            label="Copy the letter"
                            className="h-11 border bg-card"
                        />
                        {dispute.billUrl && (
                            <Button variant="outline" asChild>
                                <a
                                    href={dispute.billUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Download aria-hidden />
                                    The bill
                                </a>
                            </Button>
                        )}
                    </div>
                </div>
            </details>
            {dispute.councillorLetter && (
                <details
                    className="rounded-md border border-flag/40 bg-card"
                    open
                >
                    <summary className="flex min-h-11 cursor-pointer items-center px-4 text-sm font-semibold">
                        Send to the ward councillor
                    </summary>
                    <div className="flex flex-col gap-2 border-t px-4 py-3 text-sm">
                        <p className="font-semibold">
                            {dispute.councillorLetter.subject}
                        </p>
                        <div className="leading-6 whitespace-pre-wrap">
                            {dispute.councillorLetter.body}
                        </div>
                        <CopyButton
                            value={`${dispute.councillorLetter.subject}\n\n${dispute.councillorLetter.body}`}
                            label="Copy the letter"
                            className="h-11 self-start border bg-card"
                        />
                    </div>
                </details>
            )}
            {children}
        </li>
    );
}
