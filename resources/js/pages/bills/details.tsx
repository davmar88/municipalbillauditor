import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, Link } from '@inertiajs/react';
import { ExternalLink, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/bills';
import { update } from '@/routes/bills/details';
import type { Option, PropertyOption } from '@/types';

type ReadingRow = {
    service: string;
    meter_number: string | null;
    previous: string | null;
    current: string | null;
    consumption: string | null;
    unit: string | null;
    reading_type: string;
};

type ChargeRow = {
    service: string;
    description: string;
    amount: string;
    quantity: string | null;
    unit: string | null;
};

type Props = {
    bill: {
        id: number;
        month: string;
        status: string;
        reviewNote: string | null;
        file: { name: string | null; url: string; mime: string | null } | null;
        propertyId: number | null;
        billDate: string | null;
        dueDate: string | null;
        periodStart: string | null;
        periodEnd: string | null;
        accountNumber: string | null;
        tariffLabel: string | null;
        totalDue: string | null;
        readings: ReadingRow[];
        charges: ChargeRow[];
    };
    properties: PropertyOption[];
    services: Option[];
    readingTypes: Option[];
};

const METERED = ['water', 'electricity', 'other'];

/** "R1,234.50" and "1 234,50" both become "1234.50". */
function cleanNumber(value: FormDataConvertible): FormDataConvertible {
    if (typeof value !== 'string') {
        return value;
    }

    const trimmed = value.replace(/[R\s ]/gi, '');

    if (/^-?\d{1,3}(\.\d{3})*,\d{1,3}$/.test(trimmed)) {
        return trimmed.replace(/\./g, '').replace(',', '.');
    }

    return trimmed.replace(/,/g, '');
}

function cleanRows(
    rows: FormDataConvertible,
    fields: string[],
): FormDataConvertible {
    if (!Array.isArray(rows)) {
        return rows;
    }

    return rows.map((row) => {
        if (
            row === null ||
            typeof row !== 'object' ||
            row instanceof Blob ||
            row instanceof Date ||
            Array.isArray(row)
        ) {
            return row;
        }

        const copy: Record<string, FormDataConvertible> = { ...row };

        for (const field of fields) {
            copy[field] = cleanNumber(copy[field]);
        }

        return copy;
    });
}

let nextKey = 0;
const keyed = <T,>(rows: T[]) => rows.map((row) => ({ key: nextKey++, row }));

export default function BillDetails({
    bill,
    properties,
    services,
    readingTypes,
}: Props) {
    const [readings, setReadings] = useState(() =>
        keyed(
            bill.readings.length > 0
                ? bill.readings
                : [
                      {
                          service: 'water',
                          meter_number: null,
                          previous: null,
                          current: null,
                          consumption: null,
                          unit: 'kL',
                          reading_type: 'actual',
                      },
                  ],
        ),
    );
    const [charges, setCharges] = useState(() => keyed(bill.charges));

    return (
        <>
            <Head title="Bill details" />
            <PageBody>
                <PageHeader
                    eyebrow={
                        <Link
                            href={show(bill.id)}
                            className="underline underline-offset-[3px]"
                        >
                            Back to the bill
                        </Link>
                    }
                    title="Type in what the bill says"
                    description="Copy the figures exactly as printed. We check the bill as soon as you save."
                />

                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                    <BillPreview file={bill.file} />

                    <Form
                        {...update.form(bill.id)}
                        transform={(data) => ({
                            ...data,
                            total_due: cleanNumber(data.total_due),
                            readings: cleanRows(data.readings, [
                                'previous',
                                'current',
                                'consumption',
                            ]),
                            charges: cleanRows(data.charges, [
                                'amount',
                                'quantity',
                            ]),
                        })}
                        className="flex min-w-0 flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                {bill.reviewNote && (
                                    <p className="rounded-md border border-flag/40 bg-flag-soft/60 px-4 py-3 text-sm">
                                        {bill.reviewNote}
                                    </p>
                                )}

                                <fieldset className="grid gap-4 rounded-lg border bg-card p-5 sm:grid-cols-2">
                                    <legend className="px-1 text-base font-bold">
                                        The bill
                                    </legend>
                                    {bill.propertyId === null && (
                                        <Field
                                            label="Property"
                                            error={errors.property_id}
                                            className="sm:col-span-2"
                                        >
                                            <NativeSelect
                                                id="property_id"
                                                name="property_id"
                                                required
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    Choose a property
                                                </option>
                                                {properties.map((p) => (
                                                    <option
                                                        key={p.id}
                                                        value={p.id}
                                                    >
                                                        {p.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </Field>
                                    )}
                                    <Field
                                        label="Bill date"
                                        error={errors.bill_date}
                                    >
                                        <Input
                                            id="bill_date"
                                            name="bill_date"
                                            type="date"
                                            required
                                            defaultValue={bill.billDate ?? ''}
                                        />
                                    </Field>
                                    <Field
                                        label="Due date"
                                        error={errors.due_date}
                                    >
                                        <Input
                                            id="due_date"
                                            name="due_date"
                                            type="date"
                                            defaultValue={bill.dueDate ?? ''}
                                        />
                                    </Field>
                                    <Field
                                        label="Period from"
                                        error={errors.period_start}
                                    >
                                        <Input
                                            id="period_start"
                                            name="period_start"
                                            type="date"
                                            defaultValue={
                                                bill.periodStart ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Period to"
                                        error={errors.period_end}
                                    >
                                        <Input
                                            id="period_end"
                                            name="period_end"
                                            type="date"
                                            defaultValue={bill.periodEnd ?? ''}
                                        />
                                    </Field>
                                    <Field
                                        label="Account number"
                                        error={errors.account_number}
                                    >
                                        <Input
                                            id="account_number"
                                            name="account_number"
                                            className="font-mono"
                                            defaultValue={
                                                bill.accountNumber ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Tariff, as printed"
                                        error={errors.tariff_label}
                                    >
                                        <Input
                                            id="tariff_label"
                                            name="tariff_label"
                                            placeholder="For example: Residential"
                                            defaultValue={
                                                bill.tariffLabel ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Total due (R)"
                                        error={errors.total_due}
                                    >
                                        <Input
                                            id="total_due"
                                            name="total_due"
                                            inputMode="decimal"
                                            className="font-mono"
                                            defaultValue={bill.totalDue ?? ''}
                                        />
                                    </Field>
                                </fieldset>

                                <fieldset className="flex flex-col gap-4 rounded-lg border bg-card p-5">
                                    <legend className="px-1 text-base font-bold">
                                        Meter readings
                                    </legend>
                                    {readings.map(({ key, row }, i) => (
                                        <div
                                            key={key}
                                            className="grid gap-3 border-b pb-4 last:border-0 last:pb-0 sm:grid-cols-6"
                                        >
                                            <Field
                                                label="Service"
                                                error={
                                                    errors[
                                                        `readings.${i}.service`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <NativeSelect
                                                    name={`readings[${i}][service]`}
                                                    defaultValue={row.service}
                                                >
                                                    {services
                                                        .filter((s) =>
                                                            METERED.includes(
                                                                s.value,
                                                            ),
                                                        )
                                                        .map((s) => (
                                                            <option
                                                                key={s.value}
                                                                value={s.value}
                                                            >
                                                                {s.label}
                                                            </option>
                                                        ))}
                                                </NativeSelect>
                                            </Field>
                                            <Field
                                                label="Meter number"
                                                error={
                                                    errors[
                                                        `readings.${i}.meter_number`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <Input
                                                    name={`readings[${i}][meter_number]`}
                                                    className="font-mono"
                                                    defaultValue={
                                                        row.meter_number ?? ''
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Reading"
                                                error={
                                                    errors[
                                                        `readings.${i}.reading_type`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <NativeSelect
                                                    name={`readings[${i}][reading_type]`}
                                                    defaultValue={
                                                        row.reading_type
                                                    }
                                                >
                                                    {readingTypes.map((t) => (
                                                        <option
                                                            key={t.value}
                                                            value={t.value}
                                                        >
                                                            {t.label}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </Field>
                                            <Field
                                                label="Previous"
                                                error={
                                                    errors[
                                                        `readings.${i}.previous`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <Input
                                                    name={`readings[${i}][previous]`}
                                                    inputMode="decimal"
                                                    className="font-mono"
                                                    defaultValue={
                                                        row.previous ?? ''
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Current"
                                                error={
                                                    errors[
                                                        `readings.${i}.current`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <Input
                                                    name={`readings[${i}][current]`}
                                                    inputMode="decimal"
                                                    className="font-mono"
                                                    defaultValue={
                                                        row.current ?? ''
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Used"
                                                error={
                                                    errors[
                                                        `readings.${i}.consumption`
                                                    ]
                                                }
                                            >
                                                <Input
                                                    name={`readings[${i}][consumption]`}
                                                    inputMode="decimal"
                                                    className="font-mono"
                                                    defaultValue={
                                                        row.consumption ?? ''
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Unit"
                                                error={
                                                    errors[`readings.${i}.unit`]
                                                }
                                            >
                                                <Input
                                                    name={`readings[${i}][unit]`}
                                                    defaultValue={
                                                        row.unit ?? ''
                                                    }
                                                    placeholder="kL"
                                                />
                                            </Field>
                                            <RemoveRow
                                                label="Remove this reading"
                                                onClick={() =>
                                                    setReadings(
                                                        readings.filter(
                                                            (r) =>
                                                                r.key !== key,
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                    ))}
                                    <AddRow
                                        label="Add a meter reading"
                                        onClick={() =>
                                            setReadings([
                                                ...readings,
                                                ...keyed([
                                                    {
                                                        service: 'electricity',
                                                        meter_number: null,
                                                        previous: null,
                                                        current: null,
                                                        consumption: null,
                                                        unit: 'kWh',
                                                        reading_type: 'actual',
                                                    },
                                                ]),
                                            ])
                                        }
                                    />
                                </fieldset>

                                <fieldset className="flex flex-col gap-4 rounded-lg border bg-card p-5">
                                    <legend className="px-1 text-base font-bold">
                                        Charges
                                    </legend>
                                    {charges.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            Add each line from the charges
                                            section, such as water, sanitation
                                            and rates.
                                        </p>
                                    )}
                                    {charges.map(({ key, row }, i) => (
                                        <div
                                            key={key}
                                            className="grid gap-3 border-b pb-4 last:border-0 last:pb-0 sm:grid-cols-6"
                                        >
                                            <Field
                                                label="Service"
                                                error={
                                                    errors[
                                                        `charges.${i}.service`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <NativeSelect
                                                    name={`charges[${i}][service]`}
                                                    defaultValue={row.service}
                                                >
                                                    {services.map((s) => (
                                                        <option
                                                            key={s.value}
                                                            value={s.value}
                                                        >
                                                            {s.label}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </Field>
                                            <Field
                                                label="Description"
                                                error={
                                                    errors[
                                                        `charges.${i}.description`
                                                    ]
                                                }
                                                className="sm:col-span-4"
                                            >
                                                <Input
                                                    name={`charges[${i}][description]`}
                                                    required
                                                    defaultValue={
                                                        row.description
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Amount (R)"
                                                error={
                                                    errors[
                                                        `charges.${i}.amount`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <Input
                                                    name={`charges[${i}][amount]`}
                                                    inputMode="decimal"
                                                    required
                                                    className="font-mono"
                                                    defaultValue={row.amount}
                                                />
                                            </Field>
                                            <Field
                                                label="Quantity"
                                                error={
                                                    errors[
                                                        `charges.${i}.quantity`
                                                    ]
                                                }
                                                className="sm:col-span-2"
                                            >
                                                <Input
                                                    name={`charges[${i}][quantity]`}
                                                    inputMode="decimal"
                                                    className="font-mono"
                                                    defaultValue={
                                                        row.quantity ?? ''
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Unit"
                                                error={
                                                    errors[`charges.${i}.unit`]
                                                }
                                            >
                                                <Input
                                                    name={`charges[${i}][unit]`}
                                                    defaultValue={
                                                        row.unit ?? ''
                                                    }
                                                />
                                            </Field>
                                            <RemoveRow
                                                label="Remove this charge"
                                                onClick={() =>
                                                    setCharges(
                                                        charges.filter(
                                                            (c) =>
                                                                c.key !== key,
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                    ))}
                                    <AddRow
                                        label="Add a charge"
                                        onClick={() =>
                                            setCharges([
                                                ...charges,
                                                ...keyed([
                                                    {
                                                        service: 'water',
                                                        description: '',
                                                        amount: '',
                                                        quantity: null,
                                                        unit: null,
                                                    },
                                                ]),
                                            ])
                                        }
                                    />
                                </fieldset>

                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        Save and check
                                    </Button>
                                    <Button variant="ghost" asChild>
                                        <Link href={show(bill.id)}>Cancel</Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </PageBody>
        </>
    );
}

BillDetails.layout = {
    breadcrumbs: [{ title: 'Bills', href: index() }],
};

function Field({
    label,
    error,
    className = '',
    children,
}: {
    label: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <label className={`grid content-start gap-1.5 ${className}`}>
            <Label asChild>
                <span>{label}</span>
            </Label>
            {children}
            <InputError message={error} />
        </label>
    );
}

function AddRow({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <Button
            type="button"
            variant="outline"
            className="self-start"
            onClick={onClick}
        >
            <Plus aria-hidden />
            {label}
        </Button>
    );
}

function RemoveRow({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <div className="flex items-end sm:col-span-4 sm:justify-end">
            <Button
                type="button"
                variant="ghost"
                className="text-muted-foreground"
                onClick={onClick}
            >
                <X aria-hidden />
                {label}
            </Button>
        </div>
    );
}

function BillPreview({ file }: { file: Props['bill']['file'] }) {
    if (!file) {
        return (
            <div className="rounded-lg border border-dashed bg-card p-6 text-muted-foreground">
                No file for this bill.
            </div>
        );
    }

    return (
        <section
            aria-label="The original bill"
            className="flex flex-col gap-2 xl:sticky xl:top-4"
        >
            <a
                href={file.url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex min-h-11 items-center gap-2 self-start font-semibold text-primary underline underline-offset-[3px]"
            >
                <ExternalLink className="size-4" aria-hidden />
                Open {file.name ?? 'the bill'} in a new tab
            </a>
            {file.mime?.startsWith('image/') ? (
                <img
                    src={file.url}
                    alt="Your bill"
                    className="hidden max-h-[80vh] w-full rounded-[4px] border bg-paper object-contain xl:block"
                />
            ) : (
                <iframe
                    src={file.url}
                    title="Your bill"
                    className="hidden h-[80vh] w-full rounded-[4px] border bg-paper xl:block"
                />
            )}
        </section>
    );
}
