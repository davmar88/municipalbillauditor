import { Form, Head, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/audit/confirm-action';
import { PageBody, PageHeader } from '@/components/audit/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { destroy, index, store, update } from '@/routes/properties';
import { MunicipalityOptions } from '@/components/audit/municipality-options';
import type { MunicipalityOption, Option, PropertyDetail } from '@/types';

type Props = {
    properties: PropertyDetail[];
    municipalities: MunicipalityOption[];
    propertyTypes: Option[];
};

export default function PropertiesIndex({
    properties,
    municipalities,
    propertyTypes,
}: Props) {
    const { organisation } = usePage().props;
    const household = organisation?.kind === 'household';

    return (
        <>
            <Head title={household ? 'Property' : 'Properties'} />
            <PageBody className="max-w-5xl">
                <PageHeader
                    title={household ? 'Your property' : 'Properties'}
                    description="The suburb is used to match reported outages, and the property type to check your tariff."
                    actions={
                        <PropertyDialog
                            municipalities={municipalities}
                            propertyTypes={propertyTypes}
                            trigger={
                                <Button>
                                    <Plus aria-hidden />
                                    Add a property
                                </Button>
                            }
                        />
                    }
                />

                {properties.length === 0 ? (
                    <p className="rounded-lg border border-dashed bg-card p-5 text-muted-foreground">
                        No properties yet. Add one, or send a bill and we'll set
                        it up from the bill.
                    </p>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2">
                        {properties.map((property) => (
                            <li
                                key={property.id}
                                className="flex flex-col gap-3 rounded-lg border bg-card p-5"
                            >
                                <div className="flex flex-col">
                                    <span className="text-lg font-bold">
                                        {property.label}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {[
                                            property.address,
                                            property.suburb,
                                            property.municipality,
                                        ]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </span>
                                </div>
                                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-muted-foreground">
                                        Type
                                    </dt>
                                    <dd>{property.propertyTypeLabel}</dd>
                                    <dt className="text-muted-foreground">
                                        Account
                                    </dt>
                                    <dd className="figures text-[13px]">
                                        {property.accountNumber ?? '–'}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Bills
                                    </dt>
                                    <dd>{property.billCount ?? 0}</dd>
                                </dl>
                                <div className="flex flex-wrap gap-2">
                                    <PropertyDialog
                                        property={property}
                                        municipalities={municipalities}
                                        propertyTypes={propertyTypes}
                                        trigger={
                                            <Button variant="outline">
                                                <Pencil aria-hidden />
                                                Edit
                                            </Button>
                                        }
                                    />
                                    <ConfirmAction
                                        form={destroy.form(property.id)}
                                        trigger={
                                            <Button
                                                variant="ghost"
                                                className="text-muted-foreground"
                                            >
                                                <Trash2 aria-hidden />
                                                Delete
                                            </Button>
                                        }
                                        title={`Delete ${property.label}?`}
                                        description="The property and all its bills, files, findings and disputes are deleted for good."
                                        confirmLabel="Delete property"
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </PageBody>
        </>
    );
}

PropertiesIndex.layout = {
    breadcrumbs: [{ title: 'Properties', href: index() }],
};

function PropertyDialog({
    property,
    municipalities,
    propertyTypes,
    trigger,
}: {
    property?: PropertyDetail;
    municipalities: MunicipalityOption[];
    propertyTypes: Option[];
    trigger: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = property ? update.form(property.id) : store.form();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogTitle>
                    {property ? `Edit ${property.label}` : 'Add a property'}
                </DialogTitle>
                <DialogDescription>
                    As it appears on the municipal bill.
                </DialogDescription>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field label="Name" id="name" error={errors.name}>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="For example: Home, or Jacaranda Court"
                                    defaultValue={property?.name ?? ''}
                                />
                            </Field>
                            <Field
                                label="Unit (optional)"
                                id="unit"
                                error={errors.unit}
                            >
                                <Input
                                    id="unit"
                                    name="unit"
                                    defaultValue={property?.unit ?? ''}
                                />
                            </Field>
                            <Field
                                label="Street address"
                                id="address"
                                error={errors.address}
                            >
                                <Input
                                    id="address"
                                    name="address"
                                    autoComplete="street-address"
                                    defaultValue={property?.address ?? ''}
                                />
                            </Field>
                            <Field
                                label="Suburb"
                                id="suburb"
                                error={errors.suburb}
                            >
                                <Input
                                    id="suburb"
                                    name="suburb"
                                    defaultValue={property?.suburb ?? ''}
                                />
                            </Field>
                            <Field
                                label="Municipality"
                                id="municipality_id"
                                error={errors.municipality_id}
                            >
                                <NativeSelect
                                    id="municipality_id"
                                    name="municipality_id"
                                    defaultValue={
                                        property?.municipalityId ?? ''
                                    }
                                >
                                    <MunicipalityOptions
                                        municipalities={municipalities}
                                        placeholder="Read it from the first bill"
                                    />
                                </NativeSelect>
                            </Field>
                            <Field
                                label="What kind of property is it?"
                                id="property_type"
                                error={errors.property_type}
                            >
                                <NativeSelect
                                    id="property_type"
                                    name="property_type"
                                    required
                                    defaultValue={
                                        property?.propertyType ?? 'residential'
                                    }
                                >
                                    {propertyTypes.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Municipal account number"
                                id="account_number"
                                error={errors.account_number}
                            >
                                <Input
                                    id="account_number"
                                    name="account_number"
                                    className="font-mono"
                                    defaultValue={property?.accountNumber ?? ''}
                                />
                            </Field>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="justify-self-start"
                            >
                                {processing && <Spinner />}
                                {property ? 'Save' : 'Add property'}
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
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
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
