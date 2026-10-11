import { Form } from '@inertiajs/react';
import { Camera, Check } from 'lucide-react';
import { Chip, confidenceTone } from '@/components/audit/chip';
import { FlagNumber } from '@/components/audit/flag-number';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { meterPhoto } from '@/routes/bills';
import { answer, dismiss, restore } from '@/routes/findings';
import type { Finding } from '@/types';

type Props = {
    finding: Finding;
    billId: number;
    locked: boolean;
    meterPhotoUrl: string | null;
};

export function findingAmount(finding: Finding): string | null {
    if (finding.amount === null) {
        return null;
    }

    return finding.amountIsUpperBound
        ? `up to ${finding.amount}`
        : finding.amount;
}

/**
 * One possible error: what we found, the figures behind it, and the one
 * thing the customer can do about it.
 */
export function FindingCard({ finding, billId, locked, meterPhotoUrl }: Props) {
    const dismissed = finding.state === 'dismissed';
    const amount = findingAmount(finding);
    const answeredWith = finding.answerOptions.find(
        ([value]) => value === finding.answer,
    )?.[1];

    return (
        <article
            aria-labelledby={`finding-${finding.id}`}
            className={cn(
                'flex flex-col gap-3 rounded-lg border bg-card p-[18px]',
                dismissed && 'bg-muted/50',
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <FlagNumber number={finding.number} />
                    <Chip
                        tone={
                            dismissed
                                ? 'neutral'
                                : confidenceTone(finding.confidence)
                        }
                    >
                        {dismissed ? 'Not counted' : finding.confidenceLabel}
                    </Chip>
                    <span className="text-[13px] text-muted-foreground">
                        {finding.checkLabel}
                    </span>
                </div>
                {amount && (
                    <span
                        className={cn(
                            'figures text-[15px] font-semibold',
                            dismissed && 'text-muted-foreground line-through',
                        )}
                    >
                        {amount}
                    </span>
                )}
            </div>

            <h3
                id={`finding-${finding.id}`}
                className="text-lg leading-6 font-bold"
            >
                {finding.title}
            </h3>
            <p className="text-foreground/80">{finding.explanation}</p>

            {(finding.evidence.length > 0 || finding.calculation) &&
                !dismissed && (
                    <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 rounded-md bg-muted px-3.5 py-3 text-sm leading-5">
                        {finding.evidence.map(([label, value]) => (
                            <div key={label} className="contents">
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="figures text-[13px]">{value}</dd>
                            </div>
                        ))}
                        {finding.calculation && (
                            <div className="contents">
                                <dt className="text-muted-foreground">
                                    {finding.amountIsUpperBound
                                        ? 'At most'
                                        : 'Overcharge'}
                                </dt>
                                <dd className="figures text-[13px]">
                                    {finding.calculation}
                                </dd>
                            </div>
                        )}
                    </dl>
                )}

            {dismissed ? (
                <Dismissed finding={finding} locked={locked} />
            ) : (
                <>
                    {finding.question && finding.awaitingAnswer && !locked && (
                        <Question finding={finding} />
                    )}

                    {finding.question && answeredWith && (
                        <p className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
                            <span>
                                You answered:{' '}
                                <span className="font-semibold text-foreground">
                                    {answeredWith}
                                </span>
                            </span>
                            {!locked && (
                                <RestoreButton finding={finding}>
                                    Change
                                </RestoreButton>
                            )}
                        </p>
                    )}

                    {finding.action === 'meter_photo' && (
                        <MeterPhoto
                            billId={billId}
                            url={meterPhotoUrl}
                            locked={locked}
                        />
                    )}

                    {!locked && !finding.awaitingAnswer && (
                        <Form
                            {...dismiss.form(finding.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11 text-sm font-semibold text-foreground/80 underline underline-offset-[3px] hover:text-foreground"
                                >
                                    Not an error
                                </button>
                            )}
                        </Form>
                    )}
                </>
            )}
        </article>
    );
}

function Question({ finding }: { finding: Finding }) {
    return (
        <Form
            {...answer.form(finding.id)}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-2 rounded-md bg-action-soft/60 px-3.5 py-3"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm leading-5 font-semibold text-action-ink">
                        {finding.question}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {finding.answerOptions.map(([value, label]) => (
                            <Button
                                key={value}
                                type="submit"
                                name="answer"
                                value={value}
                                variant="outline"
                                disabled={processing}
                                className="bg-card"
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                    <InputError message={errors.answer} />
                </>
            )}
        </Form>
    );
}

function Dismissed({ finding, locked }: { finding: Finding; locked: boolean }) {
    return (
        <p className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
            You told us this isn't an error, so it's not in your dispute.
            {!locked && (
                <RestoreButton finding={finding}>Restore</RestoreButton>
            )}
        </p>
    );
}

function RestoreButton({
    finding,
    children,
}: {
    finding: Finding;
    children: string;
}) {
    return (
        <Form
            {...restore.form(finding.id)}
            options={{ preserveScroll: true }}
            className="inline"
        >
            {({ processing }) => (
                <button
                    type="submit"
                    disabled={processing}
                    className="min-h-11 font-semibold text-primary underline underline-offset-[3px]"
                >
                    {children}
                </button>
            )}
        </Form>
    );
}

function MeterPhoto({
    billId,
    url,
    locked,
}: {
    billId: number;
    url: string | null;
    locked: boolean;
}) {
    if (url) {
        return (
            <p className="flex items-center gap-2 text-sm">
                <Check className="size-4 text-primary" aria-hidden />
                <span>
                    Meter photo added.{' '}
                    <a
                        href={url}
                        target="_blank"
                        rel="noreferrer"
                        className="font-semibold text-primary underline underline-offset-[3px]"
                    >
                        View it
                    </a>
                </span>
            </p>
        );
    }

    if (locked) {
        return null;
    }

    return (
        <Form
            {...meterPhoto.form(billId)}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-1.5"
        >
            {({ submit, processing, errors }) => (
                <>
                    <label
                        className={cn(
                            'inline-flex h-11 w-fit cursor-pointer items-center gap-2 rounded-md bg-primary px-4 text-[15px] font-semibold text-primary-foreground hover:bg-primary/90 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                            processing && 'pointer-events-none opacity-60',
                        )}
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <Camera className="size-[18px]" aria-hidden />
                        )}
                        Add a photo of your meter
                        <input
                            type="file"
                            name="photo"
                            accept="image/jpeg,image/png,image/webp"
                            capture="environment"
                            className="sr-only"
                            onChange={() => submit()}
                        />
                    </label>
                    <span className="text-sm text-muted-foreground">
                        A dated photo of the actual reading makes the dispute
                        much stronger.
                    </span>
                    <InputError message={errors.photo} />
                </>
            )}
        </Form>
    );
}
