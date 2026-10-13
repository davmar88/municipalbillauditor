import type { ReactNode } from 'react';
import { FlagNumber } from '@/components/audit/flag-number';
import { cn } from '@/lib/utils';
import type { BillDetail, Confidence, Finding } from '@/types';

type Props = {
    bill: BillDetail;
};

/**
 * The bill as a document, with each finding's number marked in orange where
 * it applies. A solid mark is a likely error; a dashed one is worth checking
 * or waiting on the customer's answer.
 */
export function MarkedBill({ bill }: Props) {
    const confidenceOf = (numbers: number[]): Confidence | null => {
        const findings = numbers
            .map((n) => bill.findings.find((f) => f.number === n))
            .filter((f): f is Finding => f !== undefined);

        if (findings.length === 0) {
            return null;
        }

        return findings.some((f) => f.confidence === 'likely')
            ? 'likely'
            : 'possible';
    };

    return (
        <section
            aria-label="The bill, marked up"
            className="flex min-w-0 flex-col gap-5 rounded-[4px] border bg-paper px-5 py-6 font-mono text-[13px] leading-5 shadow-[0_1px_2px_rgba(18,22,28,.06),0_10px_28px_rgba(18,22,28,.07)] sm:px-9 sm:py-8"
        >
            <div className="flex items-baseline justify-between gap-3 border-b-2 border-foreground pb-2.5">
                <span className="font-sans text-[17px] font-extrabold tracking-[0.06em]">
                    STATEMENT OF ACCOUNT
                </span>
                <span className="text-muted-foreground uppercase">
                    {bill.month}
                </span>
            </div>

            <dl className="grid grid-cols-[repeat(auto-fit,minmax(min(200px,100%),1fr))] gap-x-6 gap-y-2.5">
                <Field label="ACCOUNT NUMBER" value={bill.accountNumber} />
                <Field label="BILL DATE" value={bill.billDateLabel} />
                <Field label="DUE DATE" value={bill.dueDate} />
                <Field label="PROPERTY" value={bill.property?.label} />
                <Field
                    label="TARIFF CATEGORY"
                    value={
                        bill.tariffFlags.length > 0 ? (
                            <Marked
                                confidence={confidenceOf(bill.tariffFlags)}
                                numbers={bill.tariffFlags}
                            >
                                {bill.tariffLabel ?? 'Not shown'}
                            </Marked>
                        ) : (
                            bill.tariffLabel
                        )
                    }
                />
                <Field label="PERIOD" value={bill.period} />
            </dl>

            {bill.readings.length > 0 && (
                <div className="flex flex-col gap-1.5">
                    <span className="text-[11px] font-semibold tracking-[0.08em] text-foreground/80">
                        METER READINGS
                    </span>

                    <table className="hidden w-full border-collapse sm:table">
                        <thead>
                            <tr className="text-left text-[11px] text-muted-foreground">
                                <Th>SERVICE</Th>
                                <Th>METER</Th>
                                <Th align="right">PREVIOUS</Th>
                                <Th align="right">CURRENT</Th>
                                <Th align="center">TYPE</Th>
                                <Th align="right">USED</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {bill.readings.map((reading) => {
                                const confidence = confidenceOf(reading.flags);

                                return (
                                    <tr
                                        key={reading.id}
                                        className="border-b border-dotted border-input last:border-0"
                                    >
                                        <td className="py-3 pr-2">
                                            <span className="inline-flex items-center gap-1.5">
                                                {reading.serviceLabel}
                                                {reading.flags.map((n) => (
                                                    <FlagNumber
                                                        key={n}
                                                        number={n}
                                                        size="sm"
                                                    />
                                                ))}
                                            </span>
                                        </td>
                                        <td className="px-2 py-3">
                                            {reading.meterNumber ?? '–'}
                                        </td>
                                        <td className="px-2 py-3 text-right">
                                            {reading.previous ?? '–'}
                                        </td>
                                        <td className="px-2 py-3 text-right">
                                            {reading.current ?? '–'}
                                        </td>
                                        <td className="px-2 py-3 text-center">
                                            {reading.readingType ===
                                                'estimated' && confidence ? (
                                                <Marked confidence={confidence}>
                                                    E
                                                </Marked>
                                            ) : (
                                                reading.readingTypeShort
                                            )}
                                        </td>
                                        <td className="py-3 pl-2 text-right">
                                            {confidence ? (
                                                <Marked confidence={confidence}>
                                                    {reading.consumption ?? '–'}{' '}
                                                    {reading.unit}
                                                </Marked>
                                            ) : (
                                                `${reading.consumption ?? '–'} ${reading.unit}`
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>

                    <ul className="flex flex-col sm:hidden">
                        {bill.readings.map((reading) => {
                            const confidence = confidenceOf(reading.flags);

                            return (
                                <li
                                    key={reading.id}
                                    className="flex items-start justify-between gap-3 border-b border-dotted border-input py-2.5 last:border-0"
                                >
                                    <span className="flex flex-col">
                                        <span className="inline-flex items-center gap-1.5">
                                            {reading.serviceLabel}
                                            {reading.flags.map((n) => (
                                                <FlagNumber
                                                    key={n}
                                                    number={n}
                                                    size="sm"
                                                />
                                            ))}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {reading.previous ?? '–'} →{' '}
                                            {reading.current ?? '–'}
                                        </span>
                                    </span>
                                    <span
                                        className={cn(
                                            'text-right',
                                            confidence &&
                                                'font-semibold text-flag-ink',
                                        )}
                                    >
                                        {reading.consumption ?? '–'}{' '}
                                        {reading.unit}
                                        {reading.readingType === 'estimated' &&
                                            ' (E)'}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>

                    <span className="text-[11px] text-muted-foreground">
                        E = estimated · A = actual
                    </span>
                </div>
            )}

            {bill.charges.length > 0 && (
                <div className="flex flex-col gap-1.5">
                    <span className="text-[11px] font-semibold tracking-[0.08em] text-foreground/80">
                        CHARGES
                    </span>
                    <div className="flex flex-col border-t border-foreground">
                        {bill.charges.map((charge) => {
                            const confidence = confidenceOf(charge.flags);

                            return (
                                <div
                                    key={charge.id}
                                    className="flex justify-between gap-3 border-b border-dotted border-input py-2"
                                >
                                    <span className="flex items-center gap-1.5">
                                        {charge.description}
                                        {charge.flags.map((n) => (
                                            <FlagNumber
                                                key={n}
                                                number={n}
                                                size="sm"
                                                className="sm:hidden"
                                            />
                                        ))}
                                    </span>
                                    <span
                                        className={cn(
                                            'shrink-0',
                                            confidence &&
                                                'font-semibold text-flag-ink underline decoration-flag decoration-2 underline-offset-4',
                                            confidence === 'possible' &&
                                                'decoration-dashed',
                                        )}
                                    >
                                        {charge.amount}
                                    </span>
                                </div>
                            );
                        })}
                        {bill.totalDue && (
                            <div className="flex justify-between gap-3 pt-3 text-[15px] font-semibold">
                                <span>TOTAL DUE</span>
                                <span>{bill.totalDue}</span>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}

function Field({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex flex-col">
            <dt className="text-[11px] text-muted-foreground">{label}</dt>
            <dd>{value ?? '–'}</dd>
        </div>
    );
}

function Th({
    children,
    align = 'left',
}: {
    children: string;
    align?: 'left' | 'right' | 'center';
}) {
    return (
        <th
            scope="col"
            className={cn(
                'border-b border-foreground px-2 py-1.5 font-medium first:pl-0 last:pr-0',
                align === 'right' && 'text-right',
                align === 'center' && 'text-center',
            )}
        >
            {children}
        </th>
    );
}

/** A figure circled in orange, like a pen mark on paper. */
function Marked({
    confidence,
    numbers = [],
    children,
}: {
    confidence: Confidence | null;
    numbers?: number[];
    children: ReactNode;
}) {
    return (
        <span className="inline-flex items-center gap-2">
            <span className="relative inline-block px-1 font-semibold text-flag-ink">
                {children}
                <span
                    aria-hidden
                    className={cn(
                        'absolute -inset-x-2 -inset-y-1.5 -rotate-3 rounded-[50%] border-2 border-flag',
                        confidence !== 'likely' && 'border-dashed',
                    )}
                />
            </span>
            {numbers.map((n) => (
                <FlagNumber key={n} number={n} size="sm" />
            ))}
        </span>
    );
}
