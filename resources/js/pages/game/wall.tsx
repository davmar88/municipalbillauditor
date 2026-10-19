import { Head } from '@inertiajs/react';
import { Eye, Megaphone, PartyPopper, ScrollText } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { WhyNot } from '@/components/game/why-not';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { act, clockTime, fromNow } from '@/lib/game';
import { cn } from '@/lib/utils';
import { call, pledge } from '@/routes/play/uprising';
import {
    chant,
    expose,
    festival,
    law as lawRoute,
    remember,
    secret,
} from '@/routes/play/wall';
import { UnrestMeter } from '@/components/game/unrest-meter';

type Law = {
    slot: number;
    template?: string;
    label?: string;
    value?: number | null;
    target?: string | null;
    wording?: string;
    unrest?: number;
    expiresAt?: string | null;
};

type Template = {
    value: string;
    label: string;
    explains: string;
    range: [number, number] | null;
    unit: string | null;
    target: 'faction' | 'species' | null;
    default: number | null;
};

type Props = {
    laws: Law[];
    templates: Template[];
    unrest: number;
    unrestEvents: { reason: string; amount: number; until: string }[];
    uprisingAt: number;
    risesAt: number;
    house: {
        id: number;
        name: string;
        colour: string;
        leader: string | null;
    } | null;
    canDecide: boolean;
    isLeader: boolean;
    changesLeft: number;
    secretEdit: { windowOpen: boolean; usedThisWeek: boolean };
    copy: {
        at: string;
        laws: { slot: number; wording: string; value: number | null }[];
    } | null;
    canRemember: boolean;
    goatHints: number[];
    history: {
        id: number;
        slot: number;
        wording: string;
        by: string | null;
        at: string;
        kind: 'public' | 'exposed' | 'settled';
    }[];
    uprising: {
        id: number;
        status: 'gathering' | 'open';
        pledgesNeeded: number;
        rebels: number;
        loyalists: number;
        gatheringUntil: string;
        closesAt: string | null;
        myPledge: 'rebel' | 'loyal' | null;
        houseMember: boolean;
    } | null;
    canCallUprising: boolean;
    festivalReady: boolean;
    canChant: boolean;
    factions: { id: number; name: string; colour: string }[];
    species: { value: string; label: string }[];
};

export default function WallPage(props: Props) {
    const { laws, unrest, house, copy, goatHints, history, uprising } = props;
    const [comparing, setComparing] = useState(false);
    const [horseshoes, setHorseshoes] = useState(1);

    return (
        <>
            <Head title="The Barn Wall" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <GameBar />

                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight">
                            The Barn Wall
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {house ? (
                                <>
                                    Painted by{' '}
                                    <span
                                        className="font-semibold"
                                        style={{ color: house.colour }}
                                    >
                                        {house.name}
                                    </span>
                                    {house.leader && `, led by ${house.leader}`}
                                    .
                                </>
                            ) : (
                                'Nobody holds the Farmhouse, so nobody writes the laws.'
                            )}
                        </p>
                    </div>
                    <UnrestMeter unrest={unrest} />
                </header>

                <section className="border-brand flex flex-col gap-3 rounded-xl border bg-card p-5">
                    <ol className="flex flex-col gap-3">
                        {laws.map((law) => {
                            const saved = copy?.laws.find(
                                (old) => old.slot === law.slot,
                            );
                            const hinted = goatHints.includes(law.slot);

                            return (
                                <li
                                    key={law.slot}
                                    className={cn(
                                        'flex gap-3 rounded-md p-2',
                                        hinted && 'bg-orange-500/10',
                                    )}
                                >
                                    <span className="font-mono text-primary">
                                        {law.slot}
                                    </span>
                                    <div className="flex flex-1 flex-col gap-1">
                                        <span
                                            className={cn(
                                                !law.wording &&
                                                    'text-muted-foreground italic',
                                            )}
                                        >
                                            {law.wording ??
                                                'This place on the Wall is empty.'}
                                        </span>
                                        {law.unrest !== undefined && (
                                            <span className="text-xs text-muted-foreground">
                                                {law.label} · adds {law.unrest}{' '}
                                                unrest
                                                {law.expiresAt &&
                                                    ` · runs out ${fromNow(law.expiresAt)}`}
                                            </span>
                                        )}
                                        {comparing && (
                                            <span className="text-xs text-cyan-200">
                                                Your copy:{' '}
                                                {saved?.wording ?? 'empty'}
                                            </span>
                                        )}
                                        {hinted && (
                                            <span className="text-xs text-orange-200">
                                                Your goat's eye says this law
                                                was quietly changed.
                                            </span>
                                        )}
                                    </div>
                                    {copy && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                act(expose.url(), {
                                                    slot: law.slot,
                                                })
                                            }
                                            title="Claim this law was secretly changed since your copy"
                                        >
                                            Expose
                                        </Button>
                                    )}
                                </li>
                            );
                        })}
                    </ol>

                    <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                        <Button
                            variant="secondary"
                            disabled={!props.canRemember}
                            onClick={() => act(remember.url())}
                        >
                            <ScrollText className="size-4" /> Remember the Wall
                        </Button>
                        <WhyNot
                            reason={
                                !props.canRemember &&
                                "You've already remembered the Wall today. You can again tomorrow."
                            }
                        />
                        {copy && (
                            <Button
                                variant="ghost"
                                onClick={() => setComparing(!comparing)}
                            >
                                <Eye className="size-4" />{' '}
                                {comparing
                                    ? 'Hide your copy'
                                    : 'Compare with your copy'}
                            </Button>
                        )}
                        <span className="text-xs text-muted-foreground">
                            {copy
                                ? `Your copy is from ${new Date(copy.at).toLocaleDateString('en-ZA')} at ${clockTime(copy.at)}.`
                                : 'Save a free copy each day. It proves what the Wall said if the House secretly changes it.'}
                        </span>
                    </div>
                </section>

                {props.unrestEvents.length > 0 && (
                    <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                        {props.unrestEvents.map((event, index) => (
                            <li key={index}>
                                {event.amount > 0 ? '+' : ''}
                                {event.amount} unrest: {event.reason} (fades{' '}
                                {fromNow(event.until)})
                            </li>
                        ))}
                    </ul>
                )}

                {uprising ? (
                    <section className="flex flex-col gap-3 rounded-xl border border-orange-500/40 bg-orange-500/10 p-4">
                        <h2 className="text-lg font-bold">
                            {uprising.status === 'gathering'
                                ? 'An uprising is gathering'
                                : 'The valley has risen'}
                        </h2>
                        <p className="text-sm">
                            {uprising.status === 'gathering'
                                ? `${uprising.rebels} of ${uprising.pledgesNeeded} rebels so far. It needs them ${fromNow(uprising.gatheringUntil)}.`
                                : `${uprising.rebels} rebels against ${uprising.loyalists} loyalists. It is decided ${uprising.closesAt ? fromNow(uprising.closesAt) : 'soon'}.`}
                        </p>
                        {uprising.myPledge ? (
                            <p className="text-sm text-primary">
                                You pledged as a{' '}
                                {uprising.myPledge === 'rebel'
                                    ? 'rebel'
                                    : 'loyalist'}
                                .
                            </p>
                        ) : uprising.houseMember &&
                          uprising.status === 'gathering' ? (
                            <p className="text-sm text-muted-foreground">
                                You can stand with the House if the uprising
                                gathers its rebels and opens.
                            </p>
                        ) : (
                            <div className="flex flex-wrap items-center gap-2">
                                <HorseshoeCount
                                    value={horseshoes}
                                    onChange={setHorseshoes}
                                />
                                {!uprising.houseMember && (
                                    <Button
                                        onClick={() =>
                                            act(pledge.url(uprising.id), {
                                                side: 'rebel',
                                                horseshoes,
                                            })
                                        }
                                    >
                                        Rise up
                                    </Button>
                                )}
                                {uprising.status === 'open' && (
                                    <Button
                                        variant="secondary"
                                        onClick={() =>
                                            act(pledge.url(uprising.id), {
                                                side: 'loyal',
                                                horseshoes,
                                            })
                                        }
                                    >
                                        Stand with the House
                                    </Button>
                                )}
                            </div>
                        )}
                    </section>
                ) : (
                    props.canCallUprising && (
                        <section className="flex flex-wrap items-center gap-3 rounded-xl border border-orange-500/40 bg-orange-500/10 p-4">
                            <p className="flex-1 text-sm">
                                Unrest is past {props.uprisingAt}. Anyone can
                                call an uprising now.
                            </p>
                            <HorseshoeCount
                                value={horseshoes}
                                onChange={setHorseshoes}
                            />
                            <Button
                                onClick={() => act(call.url(), { horseshoes })}
                            >
                                <Megaphone className="size-4" /> Call an
                                uprising
                            </Button>
                        </section>
                    )
                )}

                {props.canChant && (
                    <Button
                        variant="secondary"
                        className="self-start"
                        onClick={() => act(chant.url())}
                    >
                        Chant your faction's motto
                    </Button>
                )}

                {props.canDecide && <HouseControls {...props} />}

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">
                        What the Wall used to say
                    </h2>
                    {history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing yet.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2 text-sm">
                            {history.map((entry) => (
                                <li
                                    key={entry.id}
                                    className={cn(
                                        'flex flex-col',
                                        entry.kind === 'settled' &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    <span>
                                        <span className="font-mono text-primary">
                                            {entry.slot}
                                        </span>{' '}
                                        {entry.wording}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {entry.kind === 'exposed'
                                            ? 'A secret edit, exposed'
                                            : entry.kind === 'settled'
                                              ? 'The Wall has always said this'
                                              : `Painted by ${entry.by ?? 'the valley'}`}{' '}
                                        ·{' '}
                                        {new Date(entry.at).toLocaleDateString(
                                            'en-ZA',
                                        )}{' '}
                                        {clockTime(entry.at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

function HouseControls({
    laws,
    templates,
    changesLeft,
    isLeader,
    secretEdit,
    festivalReady,
    factions,
    species,
    house,
}: Props) {
    const [slot, setSlot] = useState(
        laws.find((law) => !law.template)?.slot ?? 1,
    );
    const [templateValue, setTemplateValue] = useState(templates[0].value);
    const template = templates.find((t) => t.value === templateValue)!;
    const [value, setValue] = useState<number | ''>(template.default ?? '');
    const [target, setTarget] = useState('');
    const [secretSlot, setSecretSlot] = useState<number | null>(null);
    const [secretValue, setSecretValue] = useState<number | ''>('');
    // Only a law with a number can be changed quietly.
    const numbered = laws.filter((law) => law.value != null);
    const secretLaw =
        numbered.find((law) => law.slot === secretSlot) ?? numbered[0];

    return (
        <section className="flex flex-col gap-4 rounded-xl border bg-card p-4">
            <div>
                <h2 className="text-lg font-bold">Rule the valley</h2>
                <p className="text-sm text-muted-foreground">
                    {changesLeft} {changesLeft === 1 ? 'change' : 'changes'}{' '}
                    left today. Every law shows the unrest it adds.
                </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1 text-sm">
                    Place on the Wall
                    <NativeSelect
                        value={slot}
                        onChange={(e) => setSlot(Number(e.target.value))}
                    >
                        {laws.map((law) => (
                            <option key={law.slot} value={law.slot}>
                                {law.slot}. {law.label ?? 'Empty'}
                            </option>
                        ))}
                    </NativeSelect>
                </label>
                <label className="grid gap-1 text-sm">
                    Law
                    <NativeSelect
                        value={templateValue}
                        onChange={(e) => {
                            const next = templates.find(
                                (t) => t.value === e.target.value,
                            )!;
                            setTemplateValue(next.value);
                            setValue(next.default ?? '');
                            setTarget('');
                        }}
                    >
                        {templates.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </NativeSelect>
                </label>
                {template.range && (
                    <label className="grid gap-1 text-sm">
                        {template.unit === '%' ? 'Percent' : 'Hours'} (
                        {template.range[0]} to {template.range[1]})
                        <Input
                            type="number"
                            min={template.range[0]}
                            max={template.range[1]}
                            value={value}
                            onChange={(e) =>
                                setValue(
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </label>
                )}
                {template.target === 'faction' && (
                    <label className="grid gap-1 text-sm">
                        Faction to ban
                        <NativeSelect
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                        >
                            <option value="">Pick a faction</option>
                            {factions
                                .filter((f) => f.id !== house?.id)
                                .map((f) => (
                                    <option key={f.id} value={f.id}>
                                        {f.name}
                                    </option>
                                ))}
                        </NativeSelect>
                    </label>
                )}
                {template.target === 'species' && (
                    <label className="grid gap-1 text-sm">
                        Favour a species (optional, adds unrest)
                        <NativeSelect
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                        >
                            <option value="">Nobody</option>
                            {species.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                )}
            </div>
            <p className="text-sm text-muted-foreground">{template.explains}</p>
            <div className="flex flex-wrap gap-2">
                <Button
                    disabled={changesLeft === 0}
                    onClick={() =>
                        act(lawRoute.url(), {
                            slot,
                            template: templateValue,
                            value: value === '' ? null : value,
                            target: target || null,
                        })
                    }
                >
                    Paint it on the Wall
                </Button>
                <WhyNot
                    reason={
                        changesLeft === 0 &&
                        'The House has used all its law changes for today. More come tomorrow.'
                    }
                />
                {laws.find((law) => law.slot === slot)?.template && (
                    <Button
                        variant="ghost"
                        disabled={changesLeft === 0}
                        onClick={() =>
                            act(lawRoute.url(), { slot, template: null })
                        }
                    >
                        Paint over place {slot}
                    </Button>
                )}
                <Button
                    variant="secondary"
                    disabled={!festivalReady}
                    onClick={() => act(festival.url())}
                >
                    <PartyPopper className="size-4" /> Hold a festival (500
                    Corn)
                </Button>
                <WhyNot
                    reason={
                        !festivalReady &&
                        'A festival can only be held once every 3 days. The last one was too recent.'
                    }
                />
            </div>

            {isLeader && (
                <div className="flex flex-col gap-2 rounded-lg border border-dashed p-3">
                    <h3 className="font-semibold">The secret edit</h3>
                    <p className="text-sm text-muted-foreground">
                        Once a week, between midnight and 04:00, you may change
                        one number in one law without telling anyone. Anyone
                        with an older copy of the Wall has 72 hours to catch it.
                    </p>
                    {secretEdit.usedThisWeek ? (
                        <p className="text-sm">You've used it this week.</p>
                    ) : !secretEdit.windowOpen ? (
                        <p className="text-sm">
                            Come back between midnight and 04:00.
                        </p>
                    ) : !secretLaw ? (
                        <p className="text-sm">
                            No law on the Wall has a number to change yet.
                        </p>
                    ) : (
                        <div className="flex flex-wrap items-end gap-2">
                            <NativeSelect
                                value={secretLaw.slot}
                                onChange={(e) =>
                                    setSecretSlot(Number(e.target.value))
                                }
                                className="w-48"
                                aria-label="Law to change"
                            >
                                {numbered.map((law) => (
                                    <option key={law.slot} value={law.slot}>
                                        {law.slot}. {law.label} ({law.value})
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                type="number"
                                className="w-24"
                                aria-label="New number"
                                placeholder={String(secretLaw.value)}
                                value={secretValue}
                                onChange={(e) =>
                                    setSecretValue(
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    act(secret.url(), {
                                        slot: secretLaw.slot,
                                        value: secretValue,
                                    })
                                }
                            >
                                Change it quietly
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

function HorseshoeCount({
    value,
    onChange,
}: {
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <NativeSelect
            value={value}
            onChange={(e) => onChange(Number(e.target.value))}
            className="w-36"
            aria-label="Horseshoes"
        >
            {[1, 2, 3].map((count) => (
                <option key={count} value={count}>
                    {count} {count === 1 ? 'horseshoe' : 'horseshoes'}
                </option>
            ))}
        </NativeSelect>
    );
}
