import { Form, Head, Link } from '@inertiajs/react';
import { CircleAlert, Paperclip, Pencil } from 'lucide-react';
import { useState } from 'react';
import { FlagNumber } from '@/components/audit/flag-number';
import { findingAmount } from '@/components/audit/finding-card';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import { PriceNotice } from '@/components/audit/price-notice';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { show as showBill } from '@/routes/bills';
import { index, store } from '@/routes/disputes';
import type { BillSummary, Finding, JobQuote, OrganisationKind } from '@/types';

type Props = {
    bill: BillSummary;
    dispute: {
        id: number;
        letterSubject: string;
        letterBody: string;
        disputedTotal: string;
        undisputedAmount: string | null;
        deadline: string | null;
        findings: Finding[];
    };
    municipality: {
        name: string;
        rulesVerified: boolean;
    };
    recipient: { email: string; source: string } | null;
    hasMandate: boolean;
    kind: OrganisationKind;
    quote: JobQuote;
    pendingQuestions: number;
    attachments: { name: string; url: string }[];
};

export default function DisputeCreate({
    bill,
    dispute,
    municipality,
    recipient,
    hasMandate,
    quote,
    pendingQuestions,
    attachments,
}: Props) {
    const [body, setBody] = useState(dispute.letterBody);
    const [editing, setEditing] = useState(false);
    return (
        <>
            <Head title="Your dispute" />
            <PageBody className="max-w-4xl">
                <PageHeader
                    eyebrow={
                        <Link
                            href={showBill(bill.id)}
                            className="underline underline-offset-[3px]"
                        >
                            {bill.month} bill
                        </Link>
                    }
                    title="Check your letter"
                    description={`Written in the format ${municipality.name} expects. Each municipality has its own rules, so the template changes with your address.`}
                />

                <Steps current={2} />

                {pendingQuestions > 0 && (
                    <p className="flex items-start gap-2 rounded-md bg-action-soft px-4 py-3 text-sm text-action-ink">
                        <CircleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden
                        />
                        <span>
                            {pendingQuestions === 1
                                ? 'One finding is waiting for your answer, so it is not in this letter.'
                                : `${pendingQuestions} findings are waiting for your answers, so they are not in this letter.`}{' '}
                            <Link
                                href={showBill(bill.id)}
                                className="font-semibold underline underline-offset-[3px]"
                            >
                                {pendingQuestions === 1
                                    ? 'Answer it first'
                                    : 'Answer them first'}
                            </Link>
                        </span>
                    </p>
                )}

                <Form {...store.form(bill.id)} className="flex flex-col gap-6">
                    {({ processing, errors }) => (
                        <>
                            <section
                                aria-label="Letter"
                                className="flex flex-col gap-4 rounded-[4px] border bg-paper p-5 shadow-[0_1px_2px_rgba(18,22,28,.06),0_10px_28px_rgba(18,22,28,.07)] sm:p-8"
                            >
                                <p className="text-sm text-muted-foreground">
                                    {recipient ? (
                                        <>
                                            We email it to{' '}
                                            <span className="figures text-[13px] text-foreground">
                                                {recipient.email}
                                            </span>
                                            {recipient.source === 'bill' &&
                                                ', the address printed on your bill'}
                                            , copy you in, and read the replies
                                            for you.
                                        </>
                                    ) : (
                                        <>
                                            We don't have {municipality.name}'s
                                            account queries email yet, and we
                                            never guess one. Approve it and we
                                            send it as soon as our team has the
                                            address.
                                        </>
                                    )}
                                </p>
                                <label
                                    htmlFor="letter_body"
                                    className="sr-only"
                                >
                                    Letter
                                </label>
                                <Textarea
                                    id="letter_body"
                                    name="letter_body"
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    hidden={!editing}
                                    rows={18}
                                    className="font-mono text-[13px] leading-5"
                                />
                                {!editing && (
                                    <div className="text-[15px] leading-6 whitespace-pre-wrap">
                                        {body}
                                    </div>
                                )}
                                <InputError message={errors.letter_body} />
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="self-start"
                                    onClick={() => setEditing(!editing)}
                                >
                                    <Pencil aria-hidden />
                                    {editing ? 'Done editing' : 'Edit letter'}
                                </Button>
                            </section>

                            <section
                                aria-labelledby="in-letter-h"
                                className="flex flex-col gap-3"
                            >
                                <h2
                                    id="in-letter-h"
                                    className="text-lg font-bold"
                                >
                                    In this letter
                                </h2>
                                <ul className="flex flex-col divide-y rounded-lg border bg-card">
                                    {dispute.findings.map((finding) => (
                                        <li
                                            key={finding.id}
                                            className="flex items-center gap-3 px-4 py-3"
                                        >
                                            <FlagNumber
                                                number={finding.number}
                                            />
                                            <span className="flex-1">
                                                {finding.title}
                                            </span>
                                            <span className="figures text-sm">
                                                {findingAmount(finding)}
                                            </span>
                                        </li>
                                    ))}
                                    <li className="flex flex-wrap justify-between gap-2 px-4 py-3 text-sm">
                                        <span className="text-muted-foreground">
                                            Keep paying the undisputed part
                                        </span>
                                        <span className="figures font-semibold">
                                            {dispute.undisputedAmount ?? '–'}
                                        </span>
                                    </li>
                                </ul>
                                {attachments.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="text-muted-foreground">
                                            Attached:
                                        </span>
                                        {attachments.map((file) => (
                                            <a
                                                key={file.url}
                                                href={file.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex min-h-9 items-center gap-1.5 rounded-md border bg-card px-3 font-medium hover:bg-accent"
                                            >
                                                <Paperclip
                                                    className="size-3.5"
                                                    aria-hidden
                                                />
                                                {file.name}
                                            </a>
                                        ))}
                                    </div>
                                )}
                            </section>

                            <PriceNotice
                                quote={quote}
                                action="A dispute"
                                whenShort="Top up first, then approve it here."
                            />
                            <InputError message={errors.credit} />

                            {!hasMandate && (
                                <div className="flex flex-col gap-1">
                                    <label className="flex items-start gap-3 rounded-lg border bg-card p-4 text-sm">
                                        <input
                                            type="checkbox"
                                            name="mandate"
                                            value="1"
                                            required
                                            className="mt-0.5 size-4 accent-primary"
                                        />
                                        <span>
                                            I authorise Municipal Bill Auditor
                                            to send disputes about my municipal
                                            account to the municipality on my
                                            behalf, and to follow them up and
                                            escalate them. I can withdraw this
                                            in my account settings.
                                        </span>
                                    </label>
                                    <InputError message={errors.mandate} />
                                </div>
                            )}

                            <div className="flex flex-col gap-2 border-t pt-5 sm:flex-row sm:items-center">
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={
                                        processing ||
                                        dispute.findings.length === 0 ||
                                        !quote.affordable
                                    }
                                >
                                    {processing && <Spinner />}
                                    Approve and send
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    {dispute.deadline &&
                                        `Dispute by ${dispute.deadline}${municipality.rulesVerified ? '' : ' (our estimate)'}. `}
                                    Nothing is sent until you approve it. The
                                    bill and meter photo go with it.
                                </span>
                            </div>
                        </>
                    )}
                </Form>
            </PageBody>
        </>
    );
}

DisputeCreate.layout = {
    breadcrumbs: [{ title: 'Disputes', href: index() }],
};

function Steps({ current }: { current: number }) {
    const steps = ['Facts', 'Letter', 'Send'];

    return (
        <ol className="flex gap-2" aria-label="Steps">
            {steps.map((step, i) => (
                <li
                    key={step}
                    aria-current={i + 1 === current ? 'step' : undefined}
                    className={cn(
                        'flex-1 border-t-4 pt-1.5 text-sm font-semibold',
                        i + 1 < current && 'border-primary text-foreground',
                        i + 1 === current && 'border-primary text-primary',
                        i + 1 > current && 'border-input text-muted-foreground',
                    )}
                >
                    {i + 1} · {step}
                </li>
            ))}
        </ol>
    );
}
