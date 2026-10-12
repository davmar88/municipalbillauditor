import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    Check,
    CircleAlert,
    Download,
    FileText,
    Pencil,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { ConfirmAction } from '@/components/audit/confirm-action';
import { FindingCard } from '@/components/audit/finding-card';
import { MarkedBill } from '@/components/audit/marked-bill';
import { MunicipalityOptions } from '@/components/audit/municipality-options';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { SummaryBand } from '@/components/audit/summary-band';
import { UsageChart, UsageKey } from '@/components/audit/usage-chart';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy,
    index,
    property as assignProperty,
    recheck,
} from '@/routes/bills';
import { edit as editDetails } from '@/routes/bills/details';
import {
    create as createDispute,
    show as showDispute,
} from '@/routes/disputes';
import {
    municipality as setMunicipality,
    index as properties,
} from '@/routes/properties';
import type {
    BillDetail,
    Finding,
    MunicipalityOption,
    PropertyOption,
} from '@/types';

type Props = {
    bill: BillDetail;
    properties: PropertyOption[];
    municipalities: MunicipalityOption[];
};

export default function BillShow({
    bill,
    properties: propertyOptions,
    municipalities,
}: Props) {
    const reading = bill.status === 'received' || bill.status === 'reading';

    return (
        <>
            <Head title={`${bill.month} bill`} />
            <PageBody>
                <PageHeader
                    eyebrow={
                        bill.property
                            ? [bill.property.label, bill.municipality?.name]
                                  .filter(Boolean)
                                  .join(' · ')
                            : 'Bill'
                    }
                    title={reading ? 'Reading your bill' : `${bill.month} bill`}
                    description={
                        bill.status === 'checked' && (
                            <>
                                {bill.accountNumber && (
                                    <>
                                        Account{' '}
                                        <span className="figures text-sm">
                                            {bill.accountNumber}
                                        </span>
                                    </>
                                )}
                                {bill.billDateLabel &&
                                    ` · Bill date ${bill.billDateLabel}`}
                            </>
                        )
                    }
                    actions={<HeaderActions bill={bill} />}
                />

                {reading && <Reading bill={bill} />}
                {bill.status === 'needs_entry' && <NeedsEntry bill={bill} />}
                {bill.status === 'needs_property' && (
                    <NeedsProperty bill={bill} options={propertyOptions} />
                )}
                {bill.property && !bill.municipality && !reading && (
                    <ChooseMunicipality
                        propertyId={bill.property.id}
                        options={municipalities}
                    />
                )}
                {bill.status === 'checked' && <Checked bill={bill} />}
            </PageBody>
        </>
    );
}

BillShow.layout = {
    breadcrumbs: [{ title: 'Bills', href: index() }],
};

function HeaderActions({ bill }: { bill: BillDetail }) {
    return (
        <>
            {bill.file && (
                <Button variant="outline" asChild>
                    <a href={bill.file.url} target="_blank" rel="noreferrer">
                        <Download aria-hidden />
                        Original bill
                    </a>
                </Button>
            )}
            {bill.dispute?.submitted ? (
                <Button asChild>
                    <Link href={showDispute(bill.dispute.id)}>
                        <FileText aria-hidden />
                        View dispute
                    </Link>
                </Button>
            ) : (
                bill.canDispute && (
                    <Button asChild>
                        <Link href={createDispute(bill.id)}>Draft dispute</Link>
                    </Button>
                )
            )}
        </>
    );
}

/** Polls until the reader has finished with the bill. */
function Reading({ bill }: { bill: BillDetail }) {
    usePoll(3000, { only: ['bill', 'properties'] });

    return (
        <section className="flex flex-col items-start gap-3 rounded-lg border bg-card p-6">
            <p className="flex items-center gap-3 text-lg font-semibold">
                <Spinner className="size-5" />
                We're reading the meter readings, tariff and charges.
            </p>
            <p className="text-muted-foreground">
                This usually takes under a minute. You can leave this page; the
                bill will be in your list when it's checked.
            </p>
            {bill.file && (
                <p className="text-sm text-muted-foreground">
                    File: {bill.file.name}
                </p>
            )}
        </section>
    );
}

function NeedsEntry({ bill }: { bill: BillDetail }) {
    return (
        <section className="flex flex-col items-start gap-3 rounded-lg border border-flag/40 bg-card p-6">
            <h2 className="flex items-center gap-2 text-lg font-bold">
                <CircleAlert className="size-5 text-flag" aria-hidden />
                We need a few details from this bill
            </h2>
            <p className="text-foreground/80">
                {bill.reviewNote ??
                    "We couldn't read everything on this bill automatically."}{' '}
                Type in what the bill says and we'll check it straight away.
            </p>
            <Button asChild>
                <Link href={editDetails(bill.id)}>
                    <Pencil aria-hidden />
                    Type in the details
                </Link>
            </Button>
        </section>
    );
}

function NeedsProperty({
    bill,
    options,
}: {
    bill: BillDetail;
    options: PropertyOption[];
}) {
    return (
        <section className="flex max-w-xl flex-col gap-4 rounded-lg border bg-card p-6">
            <h2 className="text-lg font-bold">
                Which property is this bill for?
            </h2>
            <p className="text-foreground/80">
                {bill.accountNumber
                    ? `No property has account number ${bill.accountNumber} yet. Choose one, and we'll remember the account number for next time.`
                    : 'Choose the property this bill belongs to.'}
            </p>
            {options.length > 0 ? (
                <Form
                    {...assignProperty.form(bill.id)}
                    className="flex flex-col gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="property_id">Property</Label>
                                <NativeSelect
                                    id="property_id"
                                    name="property_id"
                                    required
                                    defaultValue=""
                                >
                                    <option value="" disabled>
                                        Choose a property
                                    </option>
                                    {options.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errors.property_id} />
                            </div>
                            <Button
                                type="submit"
                                className="self-start"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Check this bill
                            </Button>
                        </>
                    )}
                </Form>
            ) : null}
            <p className="text-sm text-muted-foreground">
                Not listed?{' '}
                <Link
                    href={properties()}
                    className="font-semibold text-primary underline underline-offset-[3px]"
                >
                    Add the property
                </Link>{' '}
                first.
            </p>
        </section>
    );
}

/**
 * Shown when the name on the bill didn't tell us which municipality sent
 * it, or matched two of them.
 */
function ChooseMunicipality({
    propertyId,
    options,
}: {
    propertyId: number;
    options: MunicipalityOption[];
}) {
    return (
        <section className="border-action/40 flex max-w-xl flex-col gap-4 rounded-lg border bg-card p-6">
            <h2 className="text-lg font-bold">
                Which municipality sent this bill?
            </h2>
            <p className="text-foreground/80">
                We couldn't tell from the bill. Choose it once and we'll know
                where to send disputes for this property.
            </p>
            <Form
                {...setMunicipality.form(propertyId)}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="municipality_id">
                                Municipality
                            </Label>
                            <NativeSelect
                                id="municipality_id"
                                name="municipality_id"
                                required
                                defaultValue=""
                            >
                                <MunicipalityOptions
                                    municipalities={options}
                                    placeholder="Choose a municipality"
                                />
                            </NativeSelect>
                            <InputError message={errors.municipality_id} />
                        </div>
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

function Checked({ bill }: { bill: BillDetail }) {
    const open = bill.findings.filter((f) => f.state !== 'dismissed');
    const dismissed = bill.findings.filter((f) => f.state === 'dismissed');
    const likelyCount = open.filter((f) => f.confidence === 'likely').length;

    return (
        <>
            {bill.dispute?.submitted && (
                <Notice>
                    This bill is in a dispute, so its findings are locked.{' '}
                    <Link
                        href={showDispute(bill.dispute.id)}
                        className="font-semibold text-primary underline underline-offset-[3px]"
                    >
                        Track the dispute
                    </Link>
                </Notice>
            )}

            {bill.needsReview && !bill.locked && (
                <Notice>
                    Some figures didn't add up when we read this bill
                    {bill.reviewNote ? `: ${bill.reviewNote}` : '.'}{' '}
                    <Link
                        href={editDetails(bill.id)}
                        className="font-semibold text-primary underline underline-offset-[3px]"
                    >
                        Check the details
                    </Link>
                </Notice>
            )}

            {open.length > 0 ? (
                <SummaryBand
                    items={[
                        {
                            label:
                                likelyCount === 1
                                    ? '1 likely error'
                                    : `${likelyCount} likely errors`,
                            value: (
                                <span className="figures">
                                    {bill.likelyTotal ?? 'R0.00'}
                                </span>
                            ),
                        },
                        {
                            label: 'Most this bill could be wrong by',
                            value: (
                                <span className="figures">
                                    {bill.upToTotal
                                        ? `up to ${bill.upToTotal}`
                                        : '–'}
                                </span>
                            ),
                        },
                        {
                            label: bill.deadline?.verified
                                ? 'Dispute by'
                                : 'Dispute by (estimate)',
                            value: bill.deadline ? (
                                <Deadline deadline={bill.deadline} />
                            ) : (
                                '–'
                            ),
                        },
                    ]}
                />
            ) : (
                <section className="flex items-start gap-3 rounded-lg bg-panel p-5 text-panel-foreground">
                    <Check
                        className="mt-0.5 size-6 shrink-0 text-[#a8c0ff]"
                        aria-hidden
                    />
                    <div>
                        <h2 className="text-lg font-semibold">
                            {dismissed.length > 0
                                ? 'Nothing left to dispute on this bill'
                                : 'This bill looks right'}
                        </h2>
                        <p className="text-panel-muted">
                            We keep it as usage history, so the next bill is
                            checked against it.
                        </p>
                    </div>
                </section>
            )}

            {bill.questions > 0 && !bill.locked && (
                <Notice tone="action">
                    {bill.questions === 1
                        ? 'One finding needs your answer before it can go in a dispute.'
                        : `${bill.questions} findings need your answer before they can go in a dispute.`}
                </Notice>
            )}

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                <MarkedBill bill={bill} />

                <section
                    aria-labelledby="findings-h"
                    className="flex min-w-0 flex-col gap-4"
                >
                    <h2 id="findings-h" className="text-xl font-bold">
                        Findings
                    </h2>
                    {open.length === 0 && (
                        <p className="text-muted-foreground">
                            Nothing to flag on this bill.
                        </p>
                    )}
                    {[...open, ...dismissed].map((finding: Finding) => (
                        <FindingCard
                            key={finding.id}
                            finding={finding}
                            billId={bill.id}
                            locked={bill.locked}
                            meterPhotoUrl={bill.meterPhoto}
                        />
                    ))}

                    <CheckedAndFine
                        passed={bill.passed}
                        skipped={bill.skipped}
                    />

                    {bill.canDispute && !bill.dispute?.submitted && (
                        <div className="flex flex-col gap-2 rounded-lg border bg-card p-4">
                            <Button asChild size="lg">
                                <Link href={createDispute(bill.id)}>
                                    Draft my dispute letter
                                </Link>
                            </Button>
                            <p className="text-center text-sm text-muted-foreground">
                                Flags are checks, not verdicts. Tell us if one
                                is wrong.
                            </p>
                        </div>
                    )}
                </section>
            </div>

            {bill.history.length > 0 && (
                <section
                    aria-labelledby="history-h"
                    className="flex flex-col gap-4 rounded-lg border bg-card p-5"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="history-h" className="text-lg font-bold">
                            Your use, bill by bill
                        </h2>
                        <UsageKey />
                    </div>
                    <div className="grid gap-8 lg:grid-cols-2">
                        {bill.history.map((series) => (
                            <UsageChart key={series.service} series={series} />
                        ))}
                    </div>
                </section>
            )}

            <BillTools bill={bill} />
        </>
    );
}

function Deadline({
    deadline,
}: {
    deadline: NonNullable<BillDetail['deadline']>;
}) {
    if (deadline.daysLeft < 0) {
        return (
            <>
                {deadline.date} ·{' '}
                <span className="text-[#f59e6b]">window closed</span>
            </>
        );
    }

    return (
        <>
            {deadline.date} ·{' '}
            <span className="text-[#f59e6b]">
                {deadline.daysLeft === 1
                    ? '1 day left'
                    : `${deadline.daysLeft} days left`}
            </span>
        </>
    );
}

function CheckedAndFine({
    passed,
    skipped,
}: {
    passed: string[];
    skipped: string[];
}) {
    if (passed.length === 0 && skipped.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2.5 rounded-lg border bg-card px-[18px] py-4">
            {passed.length > 0 && (
                <>
                    <h3 className="text-[15px] font-bold">Checked and fine</h3>
                    <ul className="flex flex-col gap-2">
                        {passed.map((line) => (
                            <li
                                key={line}
                                className="flex items-start gap-2.5 text-sm text-foreground/80"
                            >
                                <Check
                                    className="mt-0.5 size-4 shrink-0 text-primary"
                                    strokeWidth={2.6}
                                    aria-hidden
                                />
                                {line}
                            </li>
                        ))}
                    </ul>
                </>
            )}
            {skipped.length > 0 && (
                <>
                    <h3 className="pt-1 text-[15px] font-bold">
                        Couldn't check
                    </h3>
                    <ul className="flex list-disc flex-col gap-1.5 pl-5 text-sm text-muted-foreground">
                        {skipped.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}

function BillTools({ bill }: { bill: BillDetail }) {
    return (
        <section
            aria-label="Bill tools"
            className="flex flex-wrap items-center gap-2 border-t pt-5"
        >
            {!bill.locked && (
                <>
                    <Button variant="outline" asChild>
                        <Link href={editDetails(bill.id)}>
                            <Pencil aria-hidden />
                            Correct the details
                        </Link>
                    </Button>
                    <Form {...recheck.form(bill.id)}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw aria-hidden />
                                )}
                                Check again
                            </Button>
                        )}
                    </Form>
                </>
            )}
            <ConfirmAction
                form={destroy.form(bill.id)}
                trigger={
                    <Button variant="ghost" className="text-muted-foreground">
                        <Trash2 aria-hidden />
                        Delete this bill
                    </Button>
                }
                title="Delete this bill?"
                description="The bill, its file, its findings and any dispute about it are deleted for good."
                confirmLabel="Delete bill"
            />
        </section>
    );
}

function Notice({
    children,
    tone = 'flag',
}: {
    children: React.ReactNode;
    tone?: 'flag' | 'action';
}) {
    return (
        <p
            className={
                tone === 'flag'
                    ? 'rounded-md border border-flag/40 bg-flag-soft/60 px-4 py-3 text-sm text-foreground'
                    : 'rounded-md bg-action-soft px-4 py-3 text-sm font-semibold text-action-ink'
            }
        >
            {children}
        </p>
    );
}
