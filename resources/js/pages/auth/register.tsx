import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { cn } from '@/lib/utils';
import { store } from '@/routes/register';
import type { OrganisationKind } from '@/types';
import GoogleButton, { OrDivider } from '@/components/google-button';
import { redirect as googleRedirect } from '@/routes/google';

type Props = {
    passwordRules: string;
    googleAvailable: boolean;
};

const accountTypes: {
    value: OrganisationKind;
    label: string;
    hint: string;
    organisationLabel?: string;
}[] = [
    {
        value: 'household',
        label: 'My home',
        hint: 'Homeowners and tenants',
    },
    {
        value: 'agent',
        label: 'Rental units',
        hint: 'Landlords and managing agents',
        organisationLabel: 'Agency or portfolio name',
    },
    {
        value: 'business',
        label: 'A business',
        hint: 'Businesses and body corporates',
        organisationLabel: 'Business or body corporate name',
    },
];

export default function Register({ passwordRules, googleAvailable }: Props) {
    const { url } = usePage();
    const requested = new URLSearchParams(url.split('?')[1] ?? '').get('type');
    // One or more: a home, rental units and a business can all be signed up
    // for at once. Each becomes an account of its own to switch between.
    const [types, setTypes] = useState<OrganisationKind[]>([
        accountTypes.find((t) => t.value === requested)?.value ?? 'household',
    ]);
    const [names, setNames] = useState<
        Partial<Record<OrganisationKind, string>>
    >({});
    const chosen = accountTypes.filter((t) => types.includes(t.value));

    const toggle = (value: OrganisationKind) =>
        setTypes((current) =>
            current.includes(value)
                ? // Keep at least one.
                  current.length > 1
                    ? current.filter((v) => v !== value)
                    : current
                : [...current, value],
        );

    // Google sign-up carries the choices above to the new accounts.
    const googleHref = googleRedirect.url({
        query: {
            account_types: types,
            organisation_names: Object.fromEntries(
                chosen
                    .filter((t) => t.organisationLabel && names[t.value])
                    .map((t) => [t.value, names[t.value] as string]),
            ),
        },
    });

    return (
        <>
            <Head title="Register" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">
                                    What are you checking bills for?
                                </legend>
                                <p className="mb-2 text-xs text-muted-foreground">
                                    Pick all that apply. Each one gets its own
                                    account, and you can switch between them or
                                    add more later.
                                </p>
                                <div className="grid gap-2">
                                    {accountTypes.map((option) => {
                                        const checked = types.includes(
                                            option.value,
                                        );

                                        return (
                                            <label
                                                key={option.value}
                                                className={cn(
                                                    'flex min-h-14 cursor-pointer items-center gap-3 rounded-md border-2 bg-card px-3 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                                    checked
                                                        ? 'border-primary'
                                                        : 'border-border',
                                                )}
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="account_types[]"
                                                    value={option.value}
                                                    checked={checked}
                                                    onChange={() =>
                                                        toggle(option.value)
                                                    }
                                                    className="size-4 accent-primary"
                                                />
                                                <span className="flex flex-col">
                                                    <span className="text-sm font-semibold">
                                                        {option.label}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {option.hint}
                                                    </span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                                <InputError
                                    message={
                                        errors.account_types ??
                                        errors['account_types.0']
                                    }
                                />
                            </fieldset>

                            {chosen
                                .filter((option) => option.organisationLabel)
                                .map((option) => (
                                    <div
                                        key={option.value}
                                        className="grid gap-2"
                                    >
                                        <Label
                                            htmlFor={`organisation_name_${option.value}`}
                                        >
                                            {option.organisationLabel}
                                        </Label>
                                        <Input
                                            id={`organisation_name_${option.value}`}
                                            type="text"
                                            required
                                            autoComplete="organization"
                                            name={`organisation_names[${option.value}]`}
                                            value={names[option.value] ?? ''}
                                            onChange={(e) =>
                                                setNames((current) => ({
                                                    ...current,
                                                    [option.value]:
                                                        e.target.value,
                                                }))
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `organisation_names.${option.value}`
                                                ]
                                            }
                                        />
                                    </div>
                                ))}

                            {googleAvailable && (
                                <>
                                    <GoogleButton href={googleHref} />
                                    <OrDivider />
                                </>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="name">Your name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Full name"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={5}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Create account
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()} tabIndex={6}>
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Create your account',
    description: "Tell us whose bills you're checking.",
};
