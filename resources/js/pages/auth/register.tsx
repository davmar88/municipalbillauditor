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
    const [type, setType] = useState<OrganisationKind>(
        accountTypes.find((t) => t.value === requested)?.value ?? 'household',
    );
    const selected = accountTypes.find((t) => t.value === type)!;
    const [organisationName, setOrganisationName] = useState('');

    // Google sign-up carries the choices above to the new account.
    const googleHref = googleRedirect.url({
        query: {
            account_type: type,
            ...(selected.organisationLabel && organisationName
                ? { organisation_name: organisationName }
                : {}),
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
                                <legend className="mb-2 text-sm font-medium">
                                    What are you checking bills for?
                                </legend>
                                <div className="grid gap-2">
                                    {accountTypes.map((option) => (
                                        <label
                                            key={option.value}
                                            className={cn(
                                                'flex min-h-14 cursor-pointer items-center gap-3 rounded-md border-2 bg-card px-3 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                                type === option.value
                                                    ? 'border-primary'
                                                    : 'border-border',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="account_type"
                                                value={option.value}
                                                checked={type === option.value}
                                                onChange={() =>
                                                    setType(option.value)
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
                                    ))}
                                </div>
                                <InputError message={errors.account_type} />
                            </fieldset>

                            {selected.organisationLabel && (
                                <div className="grid gap-2">
                                    <Label htmlFor="organisation_name">
                                        {selected.organisationLabel}
                                    </Label>
                                    <Input
                                        id="organisation_name"
                                        type="text"
                                        required
                                        autoComplete="organization"
                                        name="organisation_name"
                                        value={organisationName}
                                        onChange={(e) =>
                                            setOrganisationName(e.target.value)
                                        }
                                    />
                                    <InputError
                                        message={errors.organisation_name}
                                    />
                                </div>
                            )}

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
