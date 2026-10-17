import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';
import { store } from '@/routes/play/start';

type SpeciesOption = {
    value: string;
    label: string;
    role: string;
    talent: string;
    weakSpot: string;
};

type Props = {
    valley: string;
    species: SpeciesOption[];
    languages: string[];
    suggestedName: string;
};

export default function Start({
    valley,
    species,
    languages,
    suggestedName,
}: Props) {
    const [picked, setPicked] = useState(species[0]?.value ?? 'pig');

    return (
        <>
            <Head title="Make your animal" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-8">
                <header className="flex flex-col gap-2">
                    <p className="font-mono text-xs tracking-[0.14em] text-primary">
                        WELCOME TO {valley.toUpperCase()}
                    </p>
                    <h1 className="text-3xl font-extrabold tracking-tight">
                        Make your animal
                    </h1>
                    <p className="text-muted-foreground">
                        The humans left the farm and the animals run it now.
                        Pick the animal you want to be. Each has one talent and
                        one weak spot, and any of them can lead a faction.
                    </p>
                </header>

                <Form {...store.form()} className="flex flex-col gap-6">
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="grid gap-3">
                                <legend className="mb-1 text-sm font-semibold">
                                    Your animal
                                </legend>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {species.map((option) => (
                                        <label
                                            key={option.value}
                                            className={cn(
                                                'flex cursor-pointer flex-col gap-1 rounded-lg border-2 bg-card p-4 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                                picked === option.value
                                                    ? 'border-primary'
                                                    : 'border-border',
                                            )}
                                        >
                                            <span className="flex items-center gap-2">
                                                <input
                                                    type="radio"
                                                    name="species"
                                                    value={option.value}
                                                    checked={
                                                        picked === option.value
                                                    }
                                                    onChange={() =>
                                                        setPicked(option.value)
                                                    }
                                                    className="size-4 accent-primary"
                                                />
                                                <span className="font-bold">
                                                    {option.label}
                                                </span>
                                                <span className="font-mono text-[11px] tracking-[0.1em] text-muted-foreground">
                                                    {option.role.toUpperCase()}
                                                </span>
                                            </span>
                                            <span className="text-sm">
                                                {option.talent}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {option.weakSpot}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.species} />
                            </fieldset>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        Your animal's name
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        maxLength={20}
                                        defaultValue={suggestedName}
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="language">
                                        Translate chat into
                                    </Label>
                                    <NativeSelect
                                        id="language"
                                        name="language"
                                        defaultValue="English"
                                    >
                                        {languages.map((language) => (
                                            <option
                                                key={language}
                                                value={language}
                                            >
                                                {language}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                            </div>

                            <div className="flex items-start gap-3">
                                <Checkbox id="adult" name="adult" value="1" />
                                <div className="grid gap-1">
                                    <Label htmlFor="adult">
                                        I am 18 or older
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        The test season is for adults only.
                                    </p>
                                    <InputError message={errors.adult} />
                                </div>
                            </div>

                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing}
                                className="self-start"
                            >
                                Enter the valley
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
