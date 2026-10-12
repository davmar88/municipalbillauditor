import { MunicipalityOptions } from '@/components/audit/municipality-options';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Camera, ChevronRight, FileUp, Mail } from 'lucide-react';
import { useRef, useState } from 'react';
import { billChip, Chip } from '@/components/audit/chip';
import { PageBody } from '@/components/audit/page-header';
import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { privacy } from '@/routes';
import { index, sample, show, store } from '@/routes/bills';
import type {
    BillSummary,
    MunicipalityOption,
    Option,
    PropertyOption,
} from '@/types';
import { AddressText } from '@/components/address-text';

type Props = {
    bills: BillSummary[];
    properties: PropertyOption[];
    municipalities: MunicipalityOption[];
    askMunicipality: boolean;
    checks: Option[];
    maxUploadMb: number;
};

const checkHints: Record<string, string> = {
    estimated_reading: 'A guess billed instead of your meter.',
    wrong_tariff: "A category that doesn't fit your property.",
    outage_charge: 'Billed while water or power was off.',
    usage_spike: 'A jump well above your own history.',
};

export default function BillsIndex({
    bills,
    properties,
    municipalities,
    askMunicipality,
    checks,
    maxUploadMb,
}: Props) {
    const { organisation } = usePage().props;
    const household = organisation?.kind === 'household';
    const choosesProperty = !household || properties.length > 1;

    return (
        <>
            <Head title="Check a bill" />
            <PageBody>
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                    <section
                        aria-labelledby="check-h"
                        className="flex flex-col gap-5 rounded-lg border bg-card p-5 sm:p-7"
                    >
                        <div className="flex flex-col gap-2">
                            <span className="font-mono text-xs font-semibold tracking-[0.12em] text-flag-ink">
                                {household ? 'FREE BILL CHECK' : 'CHECK A BILL'}
                            </span>
                            <h1
                                id="check-h"
                                className="text-[28px] leading-9 font-bold tracking-tight"
                            >
                                {household
                                    ? 'Is your municipal bill right?'
                                    : 'Send us a bill to check'}
                            </h1>
                            <p className="text-muted-foreground">
                                Send us your bill. We tell you what looks wrong,
                                how much it could be, and what to do next.
                            </p>
                        </div>

                        <UploadForm
                            properties={properties}
                            municipalities={municipalities}
                            askMunicipality={askMunicipality}
                            choosesProperty={choosesProperty}
                            maxUploadMb={maxUploadMb}
                        />

                        {organisation && (
                            <div className="flex flex-col gap-1 rounded-md bg-muted px-4 py-3">
                                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Mail className="size-4" aria-hidden />
                                    Or forward e-bills to
                                </span>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="figures text-sm [overflow-wrap:anywhere]">
                                        <AddressText
                                            address={
                                                organisation.forwardingAddress
                                            }
                                        />
                                    </span>
                                    <CopyButton
                                        value={organisation.forwardingAddress}
                                    />
                                </div>
                            </div>
                        )}

                        <p className="text-sm text-muted-foreground">
                            We use your bill only to check it and build your
                            dispute, and you can delete it at any time.{' '}
                            <a
                                href={privacy().url}
                                className="font-semibold text-primary underline underline-offset-[3px]"
                            >
                                How we handle your data
                            </a>
                        </p>
                    </section>

                    <div className="flex flex-col gap-6">
                        <section
                            aria-labelledby="bills-h"
                            className="flex flex-col gap-3"
                        >
                            <h2 id="bills-h" className="text-xl font-bold">
                                Your bills
                            </h2>
                            {bills.length === 0 ? (
                                <EmptyBills />
                            ) : (
                                <ul className="flex flex-col divide-y overflow-hidden rounded-lg border bg-card">
                                    {bills.map((bill) => (
                                        <BillRow key={bill.id} bill={bill} />
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section
                            aria-labelledby="checks-h"
                            className="flex flex-col gap-3"
                        >
                            <h2 id="checks-h" className="text-xl font-bold">
                                What we check
                            </h2>
                            <ol className="grid gap-3 sm:grid-cols-2">
                                {checks.map((check, i) => (
                                    <li
                                        key={check.value}
                                        className="flex gap-3 rounded-lg border bg-card p-4"
                                    >
                                        <span className="figures text-sm font-semibold text-flag-ink">
                                            {String(i + 1).padStart(2, '0')}
                                        </span>
                                        <span className="flex flex-col">
                                            <span className="font-semibold">
                                                {check.label}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {checkHints[check.value]}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    </div>
                </div>
            </PageBody>
        </>
    );
}

BillsIndex.layout = {
    breadcrumbs: [{ title: 'Bills', href: index() }],
};

function UploadForm({
    properties,
    municipalities,
    askMunicipality,
    choosesProperty,
    maxUploadMb,
}: {
    properties: PropertyOption[];
    municipalities: MunicipalityOption[];
    askMunicipality: boolean;
    choosesProperty: boolean;
    maxUploadMb: number;
}) {
    const [fileName, setFileName] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    // One "bill" field, opened either as the phone's camera or as a file
    // picker, so only the chosen file is sent.
    const pick = (mode: 'camera' | 'file') => {
        const input = fileInput.current;

        if (!input) {
            return;
        }

        if (mode === 'camera') {
            input.accept = 'image/*';
            input.setAttribute('capture', 'environment');
        } else {
            input.accept = 'application/pdf,image/jpeg,image/png,image/webp';
            input.removeAttribute('capture');
        }

        input.click();
    };

    return (
        <Form
            {...store.form()}
            className="flex flex-col gap-4"
            onFinish={() => {
                setFileName(null);

                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            }}
        >
            {({ processing, errors, submit }) => (
                <>
                    {askMunicipality && (
                        <div className="grid gap-2">
                            <Label htmlFor="municipality_id">
                                Which municipality bills you?{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <NativeSelect
                                id="municipality_id"
                                name="municipality_id"
                                defaultValue=""
                                aria-describedby="municipality_hint"
                            >
                                <MunicipalityOptions
                                    municipalities={municipalities}
                                    placeholder="Read it from my bill"
                                />
                            </NativeSelect>
                            <p
                                id="municipality_hint"
                                className="text-sm text-muted-foreground"
                            >
                                We cover every municipality in South Africa and
                                read yours from the bill.
                            </p>
                            <InputError message={errors.municipality_id} />
                        </div>
                    )}

                    {choosesProperty && properties.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="property_id">Property</Label>
                            <NativeSelect
                                id="property_id"
                                name="property_id"
                                defaultValue=""
                            >
                                <option value="">
                                    Match it from the account number
                                </option>
                                {properties.map((property) => (
                                    <option
                                        key={property.id}
                                        value={property.id}
                                    >
                                        {property.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.property_id} />
                        </div>
                    )}

                    <input
                        ref={fileInput}
                        type="file"
                        name="bill"
                        tabIndex={-1}
                        aria-hidden
                        className="sr-only"
                        onChange={(event) => {
                            const file = event.currentTarget.files?.[0];

                            if (file) {
                                setFileName(file.name);
                                submit();
                            }
                        }}
                    />

                    <div className="grid gap-2.5 sm:grid-cols-2">
                        <Button
                            type="button"
                            size="lg"
                            className="min-h-14"
                            disabled={processing}
                            onClick={() => pick('camera')}
                        >
                            <Camera className="size-5" aria-hidden />
                            Photograph your bill
                        </Button>
                        <Button
                            type="button"
                            size="lg"
                            variant="outline"
                            className="min-h-14"
                            disabled={processing}
                            onClick={() => pick('file')}
                        >
                            <FileUp className="size-5" aria-hidden />
                            Upload a PDF or photo
                        </Button>
                    </div>

                    {processing && (
                        <p className="flex items-center gap-2 text-sm">
                            <Spinner />
                            Sending {fileName ?? 'your bill'}…
                        </p>
                    )}
                    <InputError message={errors.bill} />
                    <p className="text-xs text-muted-foreground">
                        Photos (JPEG, PNG, WebP) or PDF, up to {maxUploadMb} MB.
                    </p>
                </>
            )}
        </Form>
    );
}

function BillRow({ bill }: { bill: BillSummary }) {
    const chip = billChip(bill);

    return (
        <li>
            <Link
                href={show(bill.id)}
                prefetch
                className="flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-accent/60"
            >
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="font-semibold">{bill.month}</span>
                    <span className="truncate text-sm text-muted-foreground">
                        {bill.property?.label ?? 'Property not chosen yet'}
                    </span>
                </span>
                <span className="flex flex-col items-end gap-1">
                    {bill.totalDue && (
                        <span className="figures text-sm">{bill.totalDue}</span>
                    )}
                    <Chip tone={chip.tone}>{chip.label}</Chip>
                </span>
                <ChevronRight
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden
                />
            </Link>
        </li>
    );
}

function EmptyBills() {
    return (
        <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed bg-card p-5">
            <p className="text-muted-foreground">
                No bills yet. Send your latest bill above, or see how a check
                works on a sample bill first.
            </p>
            <Form {...sample.form()}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Try a sample bill
                    </Button>
                )}
            </Form>
        </div>
    );
}
