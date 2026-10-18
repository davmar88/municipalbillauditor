import { Head, Link } from '@inertiajs/react';
import {
    Award,
    Castle,
    Crosshair,
    Flag,
    Flame,
    Gavel,
    MessageCircle,
    Palette,
    ScanEye,
    ShoppingBasket,
    Siren,
    Swords,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { GameBar } from '@/components/game/game-bar';
import { ItemPreview } from '@/components/game/item-preview';
import { ProgressBar } from '@/components/game/missions';
import { OrchardBadge } from '@/components/game/orchard-badge';
import {
    BuyButton,
    ListingPrice,
    PowerLine,
} from '@/components/game/shop-listing';
import { Button } from '@/components/ui/button';
import { clockTime, dayMonth, speciesEmoji } from '@/lib/game';
import { openChat } from '@/lib/pulse';
import { wanted } from '@/routes/play';
import type { DesignCard } from '@/types';
import type { Currency, MessageAnimal, SkillCard } from '@/types/game';

type Deeds = {
    battles: number;
    gates: number;
    arrests: number;
    exposes: number;
    missions: number;
    sales: number;
    designs: number;
    caught: number;
};

type Props = {
    animal: MessageAnimal & {
        speciesLabel: string;
        role: string;
        talent: string;
        weakSpot: string;
        factionRole: string | null;
        level: number;
        rosettes: number;
        levelFrom: number;
        nextLevelAt: number;
        seasonRosettes: number;
        joined: string;
        inPoundUntil: string | null;
        homeClosed: boolean;
    };
    biography: string;
    skills: SkillCard[];
    record: Deeds;
    /** The bounty in Apples on a warrant out for them, if there is one. */
    bounty: number | null;
    shop: {
        listings: {
            id: number;
            price: number;
            currency: Currency;
            serial: number;
            design: DesignCard;
        }[];
        levy: number;
    };
    collection: {
        items: { id: number; serial: number; design: DesignCard }[];
        count: number;
    };
};

const SKILL_LABEL: Record<SkillCard['skill'], string> = {
    market: 'Market',
    creativity: 'Creativity',
};

const RECORD: { key: keyof Deeds; label: string; icon: LucideIcon }[] = [
    { key: 'battles', label: 'Battles won', icon: Swords },
    { key: 'gates', label: 'Nights at the gate', icon: Castle },
    { key: 'arrests', label: 'Outlaws caught', icon: Gavel },
    { key: 'exposes', label: 'Secret edits caught', icon: ScanEye },
    { key: 'missions', label: 'Missions done', icon: Flag },
    { key: 'sales', label: 'Items sold', icon: ShoppingBasket },
    { key: 'designs', label: 'Designs made', icon: Palette },
    { key: 'caught', label: 'Times caught', icon: Siren },
];

/**
 * Another animal's profile, opened from its name anywhere in the game: who it
 * is, its level, skills and record, its shop and its collection.
 */
export default function AnimalProfile({
    animal,
    biography,
    skills,
    record,
    bounty,
    shop,
    collection,
}: Props) {
    return (
        <>
            <Head title={animal.name} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <GameBar />
                <header className="flex flex-col gap-3">
                    <div className="flex items-center gap-4">
                        <span
                            className="flex size-16 shrink-0 items-center justify-center rounded-full border-2 bg-background text-4xl"
                            style={{ borderColor: animal.colour ?? undefined }}
                            aria-hidden
                        >
                            {speciesEmoji(animal.species)}
                        </span>
                        <div className="flex min-w-0 flex-col gap-1">
                            <p className="font-mono text-xs tracking-[0.14em] text-primary">
                                {animal.speciesLabel.toUpperCase()} ·{' '}
                                {animal.role.toUpperCase()}
                            </p>
                            <h1 className="text-3xl font-extrabold tracking-tight break-words">
                                {animal.name}
                                <OrchardBadge member={animal.orchard} />
                            </h1>
                            <p
                                className="text-sm"
                                style={{ color: animal.colour ?? undefined }}
                            >
                                {animal.faction
                                    ? `${animal.factionRole} of ${animal.faction}`
                                    : 'Lone wolf'}
                            </p>
                        </div>
                    </div>
                    <p className="text-muted-foreground">{biography}</p>
                    <p className="text-sm text-muted-foreground">
                        In the valley since {dayMonth(animal.joined)}.
                    </p>
                    {animal.inPoundUntil && (
                        <p className="flex items-center gap-2 text-sm text-orange-300">
                            <Siren className="size-4 shrink-0" aria-hidden /> In
                            the pound until {clockTime(animal.inPoundUntil)}.
                        </p>
                    )}
                    {animal.homeClosed && (
                        <p className="flex items-center gap-2 text-sm text-orange-300">
                            <Flame className="size-4 shrink-0" aria-hidden />{' '}
                            Their home burned down. The shop is closed until
                            it's rebuilt.
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => openChat(animal.id)}>
                            <MessageCircle className="size-4" /> Message
                        </Button>
                        {bounty !== null && (
                            <Button
                                variant="outline"
                                className="border-orange-500/50 text-orange-300"
                                asChild
                            >
                                <Link href={wanted()}>
                                    <Crosshair className="size-4" /> Wanted:{' '}
                                    {bounty.toLocaleString('en-ZA')}{' '}
                                    {bounty === 1 ? 'Apple' : 'Apples'} bounty
                                </Link>
                            </Button>
                        )}
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2">
                    <div className="flex flex-col gap-2 rounded-xl border bg-card p-4">
                        <p className="flex items-center gap-2 font-bold">
                            <Award className="size-5 text-pink-300" /> Level{' '}
                            {animal.level}
                        </p>
                        <ProgressBar
                            value={animal.rosettes - animal.levelFrom}
                            max={animal.nextLevelAt - animal.levelFrom}
                            label={`Level ${animal.level}`}
                        />
                        <p className="text-sm text-muted-foreground">
                            {animal.rosettes.toLocaleString('en-ZA')} Rosettes ·{' '}
                            {animal.seasonRosettes.toLocaleString('en-ZA')} this
                            season
                        </p>
                    </div>
                    <div className="rounded-xl border bg-card p-4 text-sm">
                        <p className="font-bold">Talent</p>
                        <p>{animal.talent}</p>
                        <p className="mt-2 font-bold">Weak spot</p>
                        <p className="text-muted-foreground">
                            {animal.weakSpot}
                        </p>
                    </div>
                </section>

                <section className="grid gap-3 sm:grid-cols-2">
                    {skills.map((skill) => (
                        <div
                            key={skill.skill}
                            className="flex flex-col gap-2 rounded-xl border bg-card p-4"
                        >
                            <div className="flex items-baseline justify-between gap-2">
                                <p className="font-bold">
                                    {SKILL_LABEL[skill.skill]}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Level {skill.level}
                                </p>
                            </div>
                            <p className="text-sm font-semibold text-primary">
                                {skill.title}
                            </p>
                            <ProgressBar
                                value={skill.points - skill.from}
                                max={skill.to - skill.from}
                                label={`${SKILL_LABEL[skill.skill]} level ${skill.level}`}
                            />
                            <p className="text-xs text-muted-foreground">
                                {skill.points.toLocaleString('en-ZA')} points
                            </p>
                        </div>
                    ))}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">Record</h2>
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {RECORD.map(({ key, label, icon: Icon }) => (
                            <li
                                key={key}
                                className="flex flex-col gap-1 rounded-xl border bg-card p-3"
                            >
                                <Icon
                                    className="size-4 text-muted-foreground"
                                    aria-hidden
                                />
                                <span className="text-2xl font-extrabold">
                                    {record[key].toLocaleString('en-ZA')}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {label}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">In their shop</h2>
                    {shop.listings.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing on their shelves right now.
                        </p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {shop.listings.map((listing) => (
                                <li
                                    key={listing.id}
                                    className="flex gap-3 rounded-xl border bg-card p-3"
                                >
                                    <ItemPreview design={listing.design} />
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <p className="font-semibold">
                                            {listing.design.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {listing.design.templateLabel} #
                                            {listing.serial}
                                            {listing.design.edition &&
                                                ` of ${listing.design.edition}`}
                                        </p>
                                        {listing.design.power && (
                                            <PowerLine
                                                design={listing.design}
                                            />
                                        )}
                                        <ListingPrice
                                            listing={listing}
                                            levy={shop.levy}
                                        />
                                        {animal.homeClosed ? (
                                            <span className="text-xs text-muted-foreground">
                                                Shop closed while the home is
                                                rebuilt
                                            </span>
                                        ) : (
                                            <BuyButton
                                                listing={listing}
                                                levy={shop.levy}
                                            />
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-bold">
                        Collection
                        {collection.count > 0 && (
                            <span className="font-normal text-muted-foreground">
                                {' '}
                                · {collection.count.toLocaleString(
                                    'en-ZA',
                                )}{' '}
                                {collection.count === 1 ? 'item' : 'items'}
                            </span>
                        )}
                    </h2>
                    {collection.items.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No items yet.
                        </p>
                    ) : (
                        <ul className="flex flex-wrap gap-3">
                            {collection.items.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex w-20 flex-col items-center gap-1 text-center text-xs"
                                >
                                    <ItemPreview
                                        design={item.design}
                                        size={64}
                                    />
                                    {item.design.name} #{item.serial}
                                </li>
                            ))}
                        </ul>
                    )}
                    {collection.count > collection.items.length && (
                        <p className="text-sm text-muted-foreground">
                            And{' '}
                            {(
                                collection.count - collection.items.length
                            ).toLocaleString('en-ZA')}{' '}
                            more.
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}
