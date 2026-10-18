import { Head, Link, usePage } from '@inertiajs/react';
import { Crown, Flame, MessagesSquare, Skull } from 'lucide-react';
import { useState } from 'react';
import { FactionBase } from '@/components/game/faction-base';
import { GameBar } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { act, capitalise, clockTime } from '@/lib/game';
import { cn } from '@/lib/utils';
import { chat, crime } from '@/routes/play';
import {
    answer,
    join,
    leave,
    remove,
    role,
    rules as rulesRoute,
    store,
} from '@/routes/play/faction';
import type { BuildingKindCard, MapBuilding } from '@/types/game';

type Rules = {
    entryMode: 'open' | 'approval' | 'invite';
    minLevel: number;
    species: string[] | null;
    entryFee: number;
    acceptsCriminals: boolean;
};

type FactionSummary = {
    id: number;
    name: string;
    colour: string;
    motto: string | null;
    isHouse: boolean;
    rules: Rules;
};

type Props = {
    faction:
        | (FactionSummary & {
              treasury: number;
              base: {
                  canBuild: boolean;
                  materials: Record<string, number>;
                  memberCap: number;
                  buildings: (MapBuilding & {
                      region: { id: number; name: string };
                  })[];
                  plots: { id: number; name: string }[];
              };
              members: {
                  id: number;
                  name: string;
                  species: string;
                  level: number;
                  role: string | null;
                  homeClosed: boolean;
                  me: boolean;
              }[];
              requests: {
                  id: number;
                  name: string;
                  species: string;
                  level: number;
                  wanted: number;
              }[];
          })
        | null;
    myRole: string | null;
    buildingKinds: BuildingKindCard[];
    factions: (FactionSummary & {
        members: number;
        regions: number;
        requested: boolean;
    })[];
    founding: {
        level: number;
        corn: number;
        colours: string[];
        maxMembers: number;
    };
    species: { value: string; label: string }[];
    entryModes: { value: string; label: string }[];
    underworld: {
        level: number;
        triedToday: boolean;
        wanted: number;
        crimes: { value: string; label: string; horseshoes: number }[];
        targets: {
            id: number;
            name: string;
            faction: string;
            protected: boolean;
        }[];
        history: {
            id: number;
            kind: string;
            target: string | null;
            outcome: 'pending' | 'caught' | 'worked';
            loot: number;
            settlesAt: string;
        }[];
    } | null;
};

export default function FactionPage(props: Props) {
    const { faction, myRole } = props;

    return (
        <>
            <Head title={faction ? faction.name : 'Factions'} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <GameBar />
                {faction ? (
                    <MyFaction {...props} faction={faction} myRole={myRole} />
                ) : (
                    <LoneWolf {...props} />
                )}
            </div>
        </>
    );
}

function MyFaction({
    faction,
    myRole,
    species,
    entryModes,
    buildingKinds,
}: Props & { faction: NonNullable<Props['faction']> }) {
    const decides = myRole === 'leader' || myRole === 'council';
    const leader = myRole === 'leader';

    return (
        <>
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1
                        className="flex items-center gap-2 text-2xl font-extrabold tracking-tight"
                        style={{ color: faction.colour }}
                    >
                        {faction.isHouse && (
                            <Crown className="size-6" aria-label="The House" />
                        )}
                        {faction.name}
                    </h1>
                    {faction.motto && (
                        <p className="text-muted-foreground italic">
                            "{faction.motto}"
                        </p>
                    )}
                </div>
                <div className="flex gap-2">
                    <Button variant="secondary" asChild>
                        <Link href={chat()}>
                            <MessagesSquare className="size-4" /> Chat
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={() =>
                            confirm(
                                'Leave your faction and become a lone wolf?',
                            ) && act(leave.url())
                        }
                    >
                        Leave
                    </Button>
                </div>
            </header>

            <p className="text-sm text-muted-foreground">
                Treasury:{' '}
                <span className="font-semibold text-foreground">
                    {faction.treasury.toLocaleString('en-ZA')} Corn
                </span>
                {faction.isHouse &&
                    ' · You rule the valley from the Farmhouse.'}
            </p>

            <FactionBase
                treasury={faction.treasury}
                base={faction.base}
                kinds={buildingKinds}
            />

            <section className="flex flex-col gap-2">
                <h2 className="text-lg font-bold">Members</h2>
                <ul className="divide-y rounded-xl border bg-card">
                    {faction.members.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-wrap items-center gap-3 px-4 py-3"
                        >
                            <span className="flex-1">
                                <span className="font-semibold">
                                    {member.name}
                                </span>{' '}
                                <span className="text-sm text-muted-foreground">
                                    {capitalise(member.species)} · level{' '}
                                    {member.level}
                                    {member.homeClosed && ' · home burned'}
                                </span>
                            </span>
                            <span className="font-mono text-xs tracking-[0.1em] text-primary">
                                {member.role?.toUpperCase()}
                            </span>
                            {leader && !member.me && (
                                <NativeSelect
                                    value=""
                                    className="w-40"
                                    aria-label={`Change ${member.name}'s role`}
                                    onChange={(e) => {
                                        if (e.target.value === 'remove') {
                                            act(
                                                remove.url(member.id),
                                                {},
                                                'delete',
                                            );
                                        } else if (e.target.value) {
                                            act(
                                                role.url(member.id),
                                                { role: e.target.value },
                                                'put',
                                            );
                                        }
                                    }}
                                >
                                    <option value="">Change role</option>
                                    <option value="council">
                                        Make council
                                    </option>
                                    <option value="member">Make member</option>
                                    <option value="leader">
                                        Hand over leadership
                                    </option>
                                    <option value="remove">Remove</option>
                                </NativeSelect>
                            )}
                        </li>
                    ))}
                </ul>
            </section>

            {decides && faction.requests.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Asking to join</h2>
                    <ul className="divide-y rounded-xl border bg-card">
                        {faction.requests.map((request) => (
                            <li
                                key={request.id}
                                className="flex flex-wrap items-center gap-3 px-4 py-3"
                            >
                                <span className="flex-1">
                                    <span className="font-semibold">
                                        {request.name}
                                    </span>{' '}
                                    <span className="text-sm text-muted-foreground">
                                        {capitalise(request.species)} · level{' '}
                                        {request.level}
                                        {request.wanted > 0 &&
                                            ` · caught ${request.wanted}×`}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        act(answer.url(request.id), {
                                            accept: true,
                                        })
                                    }
                                >
                                    Let in
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        act(answer.url(request.id), {
                                            accept: false,
                                        })
                                    }
                                >
                                    Decline
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <RulesForm
                faction={faction}
                species={species}
                entryModes={entryModes}
                editable={decides}
            />
        </>
    );
}

function RulesForm({
    faction,
    species,
    entryModes,
    editable,
}: {
    faction: FactionSummary;
    species: Props['species'];
    entryModes: Props['entryModes'];
    editable: boolean;
}) {
    const [motto, setMotto] = useState(faction.motto ?? '');
    const [entryMode, setEntryMode] = useState(faction.rules.entryMode);
    const [minLevel, setMinLevel] = useState(faction.rules.minLevel);
    const [fee, setFee] = useState(faction.rules.entryFee);
    const [picked, setPicked] = useState<string[]>(faction.rules.species ?? []);
    const [criminals, setCriminals] = useState(faction.rules.acceptsCriminals);

    return (
        <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <h2 className="text-lg font-bold">Rules for joining</h2>
            <fieldset
                disabled={!editable}
                className="grid gap-3 sm:grid-cols-2"
            >
                <label className="grid gap-1 text-sm sm:col-span-2">
                    Motto
                    <Input
                        value={motto}
                        maxLength={60}
                        onChange={(e) => setMotto(e.target.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Who may join
                    <NativeSelect
                        value={entryMode}
                        onChange={(e) =>
                            setEntryMode(e.target.value as Rules['entryMode'])
                        }
                    >
                        {entryModes.map((mode) => (
                            <option key={mode.value} value={mode.value}>
                                {mode.label}
                            </option>
                        ))}
                    </NativeSelect>
                </label>
                <label className="grid gap-1 text-sm">
                    Lowest level
                    <Input
                        type="number"
                        min={1}
                        max={40}
                        value={minLevel}
                        onChange={(e) => setMinLevel(Number(e.target.value))}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Entry fee in Corn
                    <Input
                        type="number"
                        min={0}
                        max={1000}
                        value={fee}
                        onChange={(e) => setFee(Number(e.target.value))}
                    />
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="size-4 accent-primary"
                        checked={criminals}
                        onChange={(e) => setCriminals(e.target.checked)}
                    />
                    Takes animals with a criminal record
                </label>
                <div className="grid gap-1 text-sm sm:col-span-2">
                    Species it takes (none picked means all)
                    <div className="flex flex-wrap gap-2">
                        {species.map((s) => (
                            <label
                                key={s.value}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-full border px-3 py-1',
                                    picked.includes(s.value) &&
                                        'border-primary',
                                )}
                            >
                                <input
                                    type="checkbox"
                                    className="accent-primary"
                                    checked={picked.includes(s.value)}
                                    onChange={(e) =>
                                        setPicked(
                                            e.target.checked
                                                ? [...picked, s.value]
                                                : picked.filter(
                                                      (v) => v !== s.value,
                                                  ),
                                        )
                                    }
                                />
                                {s.label}
                            </label>
                        ))}
                    </div>
                </div>
            </fieldset>
            {editable && (
                <Button
                    className="self-start"
                    onClick={() =>
                        act(
                            rulesRoute.url(),
                            {
                                motto,
                                entry_mode: entryMode,
                                min_level: minLevel,
                                entry_fee: fee,
                                species: picked,
                                accepts_criminals: criminals,
                            },
                            'put',
                        )
                    }
                >
                    Save the rules
                </Button>
            )}
        </section>
    );
}

function LoneWolf({ factions, founding, underworld, entryModes }: Props) {
    const [name, setName] = useState('');
    const [colour, setColour] = useState(founding.colours[0]);
    const [motto, setMotto] = useState('');
    const { player } = usePage().props;
    const level = player?.level ?? 1;
    const corn = player?.corn ?? 0;
    const blocker =
        level < founding.level
            ? `You can found a faction from level ${founding.level}. You're level ${level}: missions and gathering earn Rosettes.`
            : corn < founding.corn
              ? `Founding costs ${founding.corn} Corn. You have ${corn}: gather and sell to earn more.`
              : name.trim().length < 3
                ? 'Give your faction a name of 3 to 30 characters.'
                : null;

    return (
        <>
            <header>
                <h1 className="text-2xl font-extrabold tracking-tight">
                    You're a lone wolf
                </h1>
                <p className="text-muted-foreground">
                    Join a faction to hold land and raid, found your own, or
                    stay alone and work the underworld.
                </p>
            </header>

            <section className="flex flex-col gap-3">
                <h2 className="text-lg font-bold">Factions in the valley</h2>
                {factions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No factions yet. Found the first one.
                    </p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {factions.map((faction) => (
                            <li
                                key={faction.id}
                                className="flex flex-col gap-2 rounded-xl border bg-card p-4"
                            >
                                <p
                                    className="flex items-center gap-2 font-bold"
                                    style={{ color: faction.colour }}
                                >
                                    {faction.isHouse && (
                                        <Crown
                                            className="size-4"
                                            aria-label="The House"
                                        />
                                    )}
                                    {faction.name}
                                </p>
                                {faction.motto && (
                                    <p className="text-sm text-muted-foreground italic">
                                        "{faction.motto}"
                                    </p>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    {faction.members}/{founding.maxMembers}{' '}
                                    members · {faction.regions} regions
                                    {faction.rules.minLevel > 1 &&
                                        ` · from level ${faction.rules.minLevel}`}
                                    {faction.rules.entryFee > 0 &&
                                        ` · ${faction.rules.entryFee} Corn to join`}
                                    {faction.rules.species &&
                                        ` · ${faction.rules.species.map(capitalise).join(', ')} only`}
                                    {!faction.rules.acceptsCriminals &&
                                        ' · no criminals'}
                                </p>
                                {faction.requested ? (
                                    <p className="text-sm text-primary">
                                        You've asked to join.
                                    </p>
                                ) : (
                                    <Button
                                        size="sm"
                                        className="self-start"
                                        onClick={() =>
                                            act(join.url(faction.id))
                                        }
                                    >
                                        {faction.rules.entryMode === 'open'
                                            ? 'Join'
                                            : 'Ask to join'}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                <h2 className="text-lg font-bold">Found a faction</h2>
                <p className="text-sm text-muted-foreground">
                    From level {founding.level}, for {founding.corn} Corn. A
                    faction needs 3 members to raid. You can set its joining
                    rules once it exists.
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1">
                        <Label htmlFor="faction-name">Name</Label>
                        <Input
                            id="faction-name"
                            value={name}
                            maxLength={30}
                            onChange={(e) => setName(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="faction-motto">Motto</Label>
                        <Input
                            id="faction-motto"
                            value={motto}
                            maxLength={60}
                            onChange={(e) => setMotto(e.target.value)}
                        />
                    </div>
                </div>
                <div
                    className="flex flex-wrap gap-2"
                    role="radiogroup"
                    aria-label="Colour"
                >
                    {founding.colours.map((c) => (
                        <button
                            key={c}
                            type="button"
                            role="radio"
                            aria-checked={colour === c}
                            aria-label={c}
                            onClick={() => setColour(c)}
                            className={cn(
                                'size-8 rounded-full border-2',
                                colour === c
                                    ? 'border-white'
                                    : 'border-transparent',
                            )}
                            style={{ backgroundColor: c }}
                        />
                    ))}
                </div>
                {blocker && (
                    <p className="text-sm text-orange-300">{blocker}</p>
                )}
                <Button
                    className="self-start"
                    disabled={blocker !== null}
                    onClick={() =>
                        act(store.url(), {
                            name,
                            colour,
                            motto,
                            entry_mode: entryModes[0]?.value,
                        })
                    }
                >
                    Found it
                </Button>
            </section>

            {underworld && (
                <Underworld underworld={underworld} factions={factions} />
            )}
        </>
    );
}

function Underworld({
    underworld,
    factions,
}: {
    underworld: NonNullable<Props['underworld']>;
    factions: Props['factions'];
}) {
    const [kind, setKind] = useState('loot');
    const [factionId, setFactionId] = useState('');
    const [playerId, setPlayerId] = useState('');
    const selected = underworld.crimes.find((c) => c.value === kind)!;
    const { player } = usePage().props;
    const level = player?.level ?? 1;
    const horseshoes = player?.horseshoes ?? 0;
    const blocker =
        level < underworld.level
            ? `Crime opens at level ${underworld.level}. You're level ${level}.`
            : underworld.triedToday
              ? 'You have already tried a crime today. Lie low until tomorrow.'
              : horseshoes < selected.horseshoes
                ? `That needs ${selected.horseshoes} horseshoes and you have ${horseshoes}.`
                : null;

    return (
        <section className="flex flex-col gap-3 rounded-xl border border-red-500/30 bg-red-500/5 p-4">
            <h2 className="flex items-center gap-2 text-lg font-bold">
                <Skull className="size-5" /> The underworld
            </h2>
            <p className="text-sm text-muted-foreground">
                From level {underworld.level}, once a day. A loot steals a tenth
                of a faction's treasury; a fire closes a member's home and shop
                for a day. Dogs and the Night Watch catch criminals, who spend 6
                hours in the pound. Nothing bought with real money is ever
                stolen or burned.
                {underworld.wanted > 0 &&
                    ` You've been caught ${underworld.wanted}×.`}
            </p>
            <div className="grid gap-3 sm:grid-cols-3">
                <NativeSelect
                    value={kind}
                    onChange={(e) => setKind(e.target.value)}
                    aria-label="Crime"
                >
                    {underworld.crimes.map((c) => (
                        <option key={c.value} value={c.value}>
                            {c.label} ({c.horseshoes} horseshoes)
                        </option>
                    ))}
                </NativeSelect>
                {kind === 'loot' ? (
                    <NativeSelect
                        value={factionId}
                        onChange={(e) => setFactionId(e.target.value)}
                        aria-label="Faction"
                    >
                        <option value="">Pick a faction</option>
                        {factions.map((f) => (
                            <option key={f.id} value={f.id}>
                                {f.name}
                            </option>
                        ))}
                    </NativeSelect>
                ) : (
                    <NativeSelect
                        value={playerId}
                        onChange={(e) => setPlayerId(e.target.value)}
                        aria-label="Home"
                    >
                        <option value="">Pick a home</option>
                        {underworld.targets.map((t) => (
                            <option
                                key={t.id}
                                value={t.id}
                                disabled={t.protected}
                            >
                                {t.name} ({t.faction})
                                {t.protected ? ', protected' : ''}
                            </option>
                        ))}
                    </NativeSelect>
                )}
                {blocker && (
                    <p className="text-sm text-orange-300">{blocker}</p>
                )}
                <Button
                    variant="destructive"
                    disabled={blocker !== null}
                    onClick={() =>
                        act(crime.url(), {
                            kind,
                            faction_id:
                                kind === 'loot' ? factionId || null : null,
                            player_id:
                                kind === 'burn' ? playerId || null : null,
                        })
                    }
                >
                    <Flame className="size-4" /> {selected.label}
                </Button>
            </div>
            {underworld.history.length > 0 && (
                <ul className="flex flex-col gap-1 text-sm">
                    {underworld.history.map((h) => (
                        <li key={h.id}>
                            {h.kind === 'loot' ? 'Loot' : 'Fire'} on{' '}
                            {h.target ?? 'someone'}:{' '}
                            {h.outcome === 'pending'
                                ? `settles at ${clockTime(h.settlesAt)}`
                                : h.outcome === 'caught'
                                  ? 'you were caught'
                                  : h.kind === 'loot'
                                    ? `worked, ${h.loot} Corn`
                                    : 'it burned'}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
