import { Form, Head } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Chip } from '@/components/audit/chip';
import { MunicipalityOptions } from '@/components/audit/municipality-options';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { reference } from '@/routes/staff';
import { update as updateMunicipality } from '@/routes/staff/municipalities';
import {
    destroy as destroyOutage,
    store as storeOutage,
} from '@/routes/staff/outages';
import {
    destroy as destroyTariff,
    store as storeTariff,
} from '@/routes/staff/tariffs';
import type { Option } from '@/types';

type Municipality = {
    id: number;
    code: string | null;
    name: string;
    shortName: string;
    province: string | null;
    category: string;
    aliases: string[];
    propertyCount: number;
    billingAddressee: string;
    billingEmail: string | null;
    billingEmailSource: string | null;
    billingEmailVerified: boolean;
    escalationEmail: string | null;
    escalationEmailVerified: boolean;
    disputeWindowDays: number;
    replyWindowDays: number;
    councillorWindowDays: number;
    rulesVerified: boolean;
    rulesNotes: string | null;
    letterParagraph: string | null;
    live: boolean;
};

type Props = {
    municipalities: Municipality[];
    outages: {
        id: number;
        municipality: string;
        suburb: string;
        service: string;
        startsOn: string;
        endsOn: string;
        source: string | null;
    }[];
    tariffs: {
        id: number;
        municipality: string;
        category: string;
        service: string;
        rate: string;
        effectiveFrom: string | null;
        source: string | null;
        verified: boolean;
    }[];
    services: Option[];
    categories: Option[];
};

export default function Reference({
    municipalities,
    outages,
    tariffs,
    services,
    categories,
}: Props) {
    return (
        <>
            <Head title="Reference data" />
            <PageBody className="max-w-5xl">
                <PageHeader
                    title="Reference data"
                    description="What the checks and letters rely on. Mark rules and rates as verified only once you've checked them against the municipality's own documents."
                />

                <MunicipalityDirectory municipalities={municipalities} />

                <section className="flex flex-col gap-4">
                    <h2 className="text-lg font-bold">Reported outages</h2>
                    <Form
                        {...storeOutage.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3 rounded-lg border bg-card p-5 sm:grid-cols-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <MunicipalitySelect
                                    municipalities={municipalities}
                                    error={errors.municipality_id}
                                    id="outage_metro"
                                />
                                <Field
                                    label="Suburb"
                                    id="outage_suburb"
                                    error={errors.suburb}
                                >
                                    <Input
                                        id="outage_suburb"
                                        name="suburb"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Service"
                                    id="outage_service"
                                    error={errors.service}
                                >
                                    <NativeSelect
                                        id="outage_service"
                                        name="service"
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
                                    label="From"
                                    id="starts_on"
                                    error={errors.starts_on}
                                >
                                    <Input
                                        id="starts_on"
                                        name="starts_on"
                                        type="date"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="To"
                                    id="ends_on"
                                    error={errors.ends_on}
                                >
                                    <Input
                                        id="ends_on"
                                        name="ends_on"
                                        type="date"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Source (link or notice)"
                                    id="outage_source"
                                    error={errors.source}
                                >
                                    <Input id="outage_source" name="source" />
                                </Field>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Add outage
                                </Button>
                            </>
                        )}
                    </Form>
                    <SimpleTable
                        empty="No outages recorded."
                        headings={[
                            'Municipality',
                            'Suburb',
                            'Service',
                            'Dates',
                            'Source',
                        ]}
                        rows={outages.map((o) => ({
                            id: o.id,
                            cells: [
                                o.municipality,
                                o.suburb,
                                o.service,
                                `${o.startsOn} to ${o.endsOn}`,
                                o.source ?? '–',
                            ],
                            remove: destroyOutage.form(o.id),
                        }))}
                    />
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-lg font-bold">Tariff rates</h2>
                    <Form
                        {...storeTariff.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3 rounded-lg border bg-card p-5 sm:grid-cols-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <MunicipalitySelect
                                    municipalities={municipalities}
                                    error={errors.municipality_id}
                                    id="tariff_metro"
                                />
                                <Field
                                    label="Category"
                                    id="category"
                                    error={errors.category}
                                >
                                    <NativeSelect id="category" name="category">
                                        {categories.map((c) => (
                                            <option
                                                key={c.value}
                                                value={c.value}
                                            >
                                                {c.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field
                                    label="Service"
                                    id="tariff_service"
                                    error={errors.service}
                                >
                                    <NativeSelect
                                        id="tariff_service"
                                        name="service"
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
                                    label="Rate (R per unit)"
                                    id="rate"
                                    error={errors.rate}
                                >
                                    <Input
                                        id="rate"
                                        name="rate"
                                        inputMode="decimal"
                                        className="font-mono"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Effective from"
                                    id="effective_from"
                                    error={errors.effective_from}
                                >
                                    <Input
                                        id="effective_from"
                                        name="effective_from"
                                        type="date"
                                    />
                                </Field>
                                <Field
                                    label="Source"
                                    id="tariff_source"
                                    error={errors.source}
                                >
                                    <Input id="tariff_source" name="source" />
                                </Field>
                                <BooleanField
                                    name="verified"
                                    label="Checked against the tariff schedule"
                                />
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Add rate
                                </Button>
                            </>
                        )}
                    </Form>
                    <SimpleTable
                        empty="No rates recorded."
                        headings={[
                            'Municipality',
                            'Category',
                            'Service',
                            'Rate',
                            'From',
                            'Status',
                        ]}
                        rows={tariffs.map((t) => ({
                            id: t.id,
                            cells: [
                                t.municipality,
                                t.category,
                                t.service,
                                t.rate,
                                t.effectiveFrom ?? '–',
                                t.verified ? 'Verified' : 'Illustrative',
                            ],
                            remove: destroyTariff.form(t.id),
                        }))}
                    />
                </section>
            </PageBody>
        </>
    );
}

Reference.layout = {
    breadcrumbs: [{ title: 'Reference data', href: reference() }],
};

/**
 * All 257 municipalities. Search by name, code or alias; without a search,
 * the ones customers' properties are in come first.
 */
function MunicipalityDirectory({
    municipalities,
}: {
    municipalities: Municipality[];
}) {
    const [query, setQuery] = useState('');
    const needle = query.trim().toLowerCase();
    const matches = needle
        ? municipalities.filter((m) =>
              [
                  m.name,
                  m.shortName,
                  m.code ?? '',
                  m.province ?? '',
                  ...m.aliases,
              ]
                  .join(' ')
                  .toLowerCase()
                  .includes(needle),
          )
        : municipalities.filter((m) => m.propertyCount > 0);
    const shown = matches.slice(0, 12);
    const withEmail = municipalities.filter((m) => m.billingEmail).length;
    const verified = municipalities.filter(
        (m) => m.billingEmailVerified,
    ).length;

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-lg font-bold">Municipalities</h2>
                <p className="text-sm text-muted-foreground">
                    {municipalities.length} in the directory. {withEmail} have a
                    billing email, {verified} of them verified by us. A dispute
                    goes to a verified address first, then the one printed on
                    the customer's bill, then an unverified one.
                </p>
            </div>
            <div className="grid max-w-md gap-1.5">
                <Label htmlFor="municipality_search">Find a municipality</Label>
                <Input
                    id="municipality_search"
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Name, code or province"
                />
            </div>
            {!needle && (
                <p className="text-sm text-muted-foreground">
                    Showing the municipalities our customers' properties are in.
                </p>
            )}
            {shown.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No municipality matches.
                </p>
            )}
            {shown.map((municipality) => (
                <MunicipalityForm
                    key={municipality.id}
                    municipality={municipality}
                />
            ))}
            {matches.length > shown.length && (
                <p className="text-sm text-muted-foreground">
                    {matches.length - shown.length} more. Narrow the search to
                    see them.
                </p>
            )}
        </section>
    );
}

function MunicipalityForm({ municipality }: { municipality: Municipality }) {
    return (
        <Form
            {...updateMunicipality.form(municipality.id)}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-4 rounded-lg border bg-card p-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-base font-bold">
                            {municipality.name}
                        </h3>
                        <span className="figures text-xs text-muted-foreground">
                            {[municipality.code, municipality.province]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                        <Chip
                            tone={
                                municipality.rulesVerified
                                    ? 'action'
                                    : 'flag-outline'
                            }
                        >
                            {municipality.rulesVerified
                                ? 'Rules verified'
                                : 'Rules not verified'}
                        </Chip>
                        {!municipality.live && (
                            <Chip tone="missing">Not live</Chip>
                        )}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            label="Addressee"
                            id={`addressee_${municipality.id}`}
                            error={errors.billing_addressee}
                        >
                            <Input
                                id={`addressee_${municipality.id}`}
                                name="billing_addressee"
                                defaultValue={municipality.billingAddressee}
                                required
                            />
                        </Field>
                        <Field
                            label="Billing email"
                            id={`email_${municipality.id}`}
                            error={errors.billing_email}
                        >
                            <Input
                                id={`email_${municipality.id}`}
                                name="billing_email"
                                type="email"
                                defaultValue={municipality.billingEmail ?? ''}
                            />
                            {municipality.billingEmailSource && (
                                <span className="text-xs [overflow-wrap:anywhere] text-muted-foreground">
                                    Source: {municipality.billingEmailSource}
                                </span>
                            )}
                        </Field>
                        <Field
                            label="Escalation email (municipal manager)"
                            id={`escalation_${municipality.id}`}
                            error={errors.escalation_email}
                        >
                            <Input
                                id={`escalation_${municipality.id}`}
                                name="escalation_email"
                                type="email"
                                defaultValue={
                                    municipality.escalationEmail ?? ''
                                }
                            />
                        </Field>
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        <BooleanField
                            name="billing_email_verified"
                            label="Billing email confirmed with the municipality"
                            defaultChecked={municipality.billingEmailVerified}
                        />
                        <BooleanField
                            name="escalation_email_verified"
                            label="Escalation email confirmed"
                            defaultChecked={
                                municipality.escalationEmailVerified
                            }
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field
                            label="Days to dispute"
                            id={`dispute_${municipality.id}`}
                            error={errors.dispute_window_days}
                        >
                            <Input
                                id={`dispute_${municipality.id}`}
                                name="dispute_window_days"
                                type="number"
                                min={1}
                                defaultValue={municipality.disputeWindowDays}
                            />
                        </Field>
                        <Field
                            label="Days for a reply"
                            id={`reply_${municipality.id}`}
                            error={errors.reply_window_days}
                        >
                            <Input
                                id={`reply_${municipality.id}`}
                                name="reply_window_days"
                                type="number"
                                min={1}
                                defaultValue={municipality.replyWindowDays}
                            />
                        </Field>
                        <Field
                            label="Days for the councillor"
                            id={`councillor_${municipality.id}`}
                            error={errors.councillor_window_days}
                        >
                            <Input
                                id={`councillor_${municipality.id}`}
                                name="councillor_window_days"
                                type="number"
                                min={1}
                                defaultValue={municipality.councillorWindowDays}
                            />
                        </Field>
                    </div>
                    <Field
                        label="Where these rules come from"
                        id={`notes_${municipality.id}`}
                        error={errors.rules_notes}
                    >
                        <Textarea
                            id={`notes_${municipality.id}`}
                            name="rules_notes"
                            defaultValue={municipality.rulesNotes ?? ''}
                        />
                    </Field>
                    <Field
                        label="Extra paragraph for this municipality's letters"
                        id={`paragraph_${municipality.id}`}
                        error={errors.letter_paragraph}
                    >
                        <Textarea
                            id={`paragraph_${municipality.id}`}
                            name="letter_paragraph"
                            defaultValue={municipality.letterParagraph ?? ''}
                        />
                    </Field>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        <BooleanField
                            name="rules_verified"
                            label="Rules checked against the municipality's own documents"
                            defaultChecked={municipality.rulesVerified}
                        />
                        <BooleanField
                            name="live"
                            label="Open to customers"
                            defaultChecked={municipality.live}
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="self-start"
                    >
                        {processing && <Spinner />}
                        Save {municipality.shortName}
                    </Button>
                </>
            )}
        </Form>
    );
}

/** A checkbox that always posts 1 or 0, so unticking it is saved too. */
function BooleanField({
    name,
    label,
    defaultChecked = false,
}: {
    name: string;
    label: string;
    defaultChecked?: boolean;
}) {
    const [checked, setChecked] = useState(defaultChecked);

    return (
        <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => setChecked(e.target.checked)}
                className="size-4 accent-primary"
            />
            {label}
        </label>
    );
}

function MunicipalitySelect({
    municipalities,
    error,
    id,
}: {
    municipalities: Municipality[];
    error?: string;
    id: string;
}) {
    return (
        <Field label="Municipality" id={id} error={error}>
            <NativeSelect
                id={id}
                name="municipality_id"
                required
                defaultValue=""
            >
                <MunicipalityOptions
                    municipalities={municipalities.map((m) => ({
                        id: m.id,
                        name: m.shortName,
                        province: m.province,
                    }))}
                    placeholder="Choose a municipality"
                />
            </NativeSelect>
        </Field>
    );
}

function Field({
    label,
    id,
    error,
    children,
}: {
    label: string;
    id: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function SimpleTable({
    headings,
    rows,
    empty,
}: {
    headings: string[];
    rows: {
        id: number;
        cells: string[];
        remove: { action: string; method: 'post' };
    }[];
    empty: string;
}) {
    if (rows.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border bg-card">
            <table className="w-full min-w-[560px] text-sm">
                <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                        {headings.map((h) => (
                            <th
                                key={h}
                                scope="col"
                                className="px-4 py-2 font-medium"
                            >
                                {h}
                            </th>
                        ))}
                        <th scope="col" className="px-4 py-2">
                            <span className="sr-only">Remove</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-b last:border-0">
                            {row.cells.map((cell, i) => (
                                <td key={i} className="px-4 py-2">
                                    {cell}
                                </td>
                            ))}
                            <td className="px-2 py-1 text-right">
                                <Form
                                    {...row.remove}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            size="icon"
                                            disabled={processing}
                                            aria-label="Remove"
                                        >
                                            <Trash2 aria-hidden />
                                        </Button>
                                    )}
                                </Form>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
