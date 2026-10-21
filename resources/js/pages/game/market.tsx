import { Head, Link, usePage } from '@inertiajs/react';
import { Hammer, ShoppingBasket, Sparkles, Store } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { ItemPreview } from '@/components/game/item-preview';
import { OrchardBadge } from '@/components/game/orchard-badge';
import { ProfileLink } from '@/components/game/profile-link';
import {
    BuyButton,
    ListingPrice,
    PowerLine,
} from '@/components/game/shop-listing';
import { Button } from '@/components/ui/button';
import { WhyNot } from '@/components/game/why-not';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { act, capitalise, money } from '@/lib/game';
import { cn } from '@/lib/utils';
import {
    apples,
    craft,
    design as designRoute,
    list,
    unlist,
    use as useItem,
} from '@/routes/play';
import type { DesignCard } from '@/types';
import type { Currency } from '@/types/game';

type Props = {
    /** Pell the Irongate trader's cart: basics for Corn. */
    trader: {
        name: string;
        left: number;
        goods: {
            template: string;
            label: string;
            power: string | null;
            price: number;
        }[];
    };
    listings: {
        id: number;
        price: number;
        currency: Currency;
        serial: number;
        design: DesignCard;
        seller: {
            id: number;
            name: string;
            faction: string | null;
            colour: string | null;
            closed: boolean;
            orchard: boolean;
        };
        mine: boolean;
    }[];
    levy: number;
    bannedFaction: number | null;
    items: {
        id: number;
        serial: number;
        design: DesignCard;
        resale: boolean;
        listing: { id: number; price: number; currency: Currency } | null;
    }[];
    designs: (DesignCard & { needs: Record<string, number> })[];
    workshop: {
        templates: {
            value: DesignCard['template'];
            label: string;
            needs: Record<string, number>;
            power: string | null;
            for: DesignCard['for'];
            creativity: number;
        }[];
        creativity: number;
        palette: string[];
        patterns: string[];
        editions: number[];
    };
    shop: {
        level: number;
        shelves: number;
        orchard: boolean;
        used: number;
        /** The lowest and highest price, in each currency. */
        prices: Record<Currency, [number, number]>;
        /** The game's share of an Apple sale, in percent. */
        cut: number;
        /** A maker's royalty on a resale, in percent. */
        royalty: number;
        /** Items sell for Apples only. */
        applesOnly: boolean;
    };
};

const tabs = [
    { key: 'browse', label: 'Market Square', icon: ShoppingBasket },
    { key: 'shop', label: 'My shop', icon: Store },
    { key: 'workshop', label: 'Workshop', icon: Hammer },
] as const;

export default function MarketPage(props: Props) {
    const [tab, setTab] = useState<(typeof tabs)[number]['key']>('browse');

    return (
        <>
            <Head title="Market" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
                <GameBar />
                <header>
                    <h1 className="text-2xl font-extrabold tracking-tight">
                        The market
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {props.shop.applesOnly ? (
                            'Everything here is made by players and sold for Apples.'
                        ) : (
                            <>
                                Everything here is made by players. Prices are
                                in Corn
                                {props.levy > 0 &&
                                    `, plus the House's ${props.levy}% market levy`}
                                , or in Apples.
                            </>
                        )}
                    </p>
                </header>
                <nav className="flex gap-2" aria-label="Market sections">
                    {tabs.map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => setTab(t.key)}
                            aria-pressed={tab === t.key}
                            className={cn(
                                'flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold',
                                tab === t.key
                                    ? 'border-primary bg-primary/15'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <t.icon className="size-4" /> {t.label}
                        </button>
                    ))}
                </nav>
                {tab === 'browse' && (
                    <Browse {...props} onWorkshop={() => setTab('workshop')} />
                )}
                {tab === 'shop' && <MyShop {...props} />}
                {tab === 'workshop' && <Workshop {...props} />}
            </div>
        </>
    );
}

function Browse(props: Props & { onWorkshop: () => void }) {
    return (
        <div className="flex flex-col gap-4">
            {/* Pell's cart is off the Market Square (her call, 2026-10-21). */}
            <Shelves {...props} />
        </div>
    );
}

function Shelves({
    listings,
    levy,
    onWorkshop,
}: Props & { onWorkshop: () => void }) {
    if (listings.length === 0) {
        return (
            <div className="flex flex-col items-start gap-3 rounded-xl border border-dashed p-6">
                <p className="font-semibold">The shelves are empty.</p>
                <p className="text-sm text-muted-foreground">
                    Everything sold here is made by animals like you. Design
                    something in the Workshop, make copies from the materials
                    you gather, and be the first to sell.
                </p>
                <Button onClick={onWorkshop}>
                    <Hammer className="size-4" /> Open the Workshop
                </Button>
            </div>
        );
    }

    // Copies of one design a seller has at one price stack into one card.
    const stacks = new Map<string, Props['listings']>();
    for (const listing of listings) {
        const key = `${listing.seller.id}|${listing.design.id}|${listing.price}|${listing.currency}`;
        stacks.set(key, [...(stacks.get(key) ?? []), listing]);
    }

    return (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {[...stacks.values()].map((stack) => {
                const listing = stack.reduce((a, b) =>
                    b.serial < a.serial ? b : a,
                );

                return (
                    <li
                        key={listing.id}
                        className="flex gap-3 rounded-xl border bg-card p-3"
                    >
                        <ItemPreview design={listing.design} />
                        <div className="flex flex-1 flex-col gap-1">
                            <p className="font-semibold">
                                {listing.design.name}
                                {stack.length > 1 && (
                                    <span className="ml-1.5 rounded-full bg-muted px-1.5 text-xs">
                                        ×{stack.length}
                                    </span>
                                )}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {listing.design.templateLabel} #{listing.serial}
                                {stack.length > 1 &&
                                    `–${Math.max(...stack.map((l) => l.serial))}`}
                                {listing.design.edition &&
                                    ` of ${listing.design.edition}`}{' '}
                                · by{' '}
                                {listing.mine ? (
                                    <span
                                        style={
                                            listing.seller.colour
                                                ? {
                                                      color: listing.seller
                                                          .colour,
                                                  }
                                                : undefined
                                        }
                                    >
                                        {listing.seller.name}
                                        <OrchardBadge
                                            member={listing.seller.orchard}
                                        />
                                    </span>
                                ) : (
                                    <ProfileLink
                                        animal={listing.seller}
                                        style={
                                            listing.seller.colour
                                                ? {
                                                      color: listing.seller
                                                          .colour,
                                                  }
                                                : undefined
                                        }
                                    >
                                        {listing.seller.name}
                                        <OrchardBadge
                                            member={listing.seller.orchard}
                                        />
                                    </ProfileLink>
                                )}
                            </p>
                            {listing.design.power && (
                                <PowerLine design={listing.design} />
                            )}
                            <ListingPrice listing={listing} levy={levy} />
                            {listing.mine ? (
                                <span className="text-xs text-primary">
                                    Your listing
                                </span>
                            ) : listing.seller.closed ? (
                                <span className="text-xs text-muted-foreground">
                                    Shop closed while the home is rebuilt
                                </span>
                            ) : (
                                <BuyButton listing={listing} levy={levy} />
                            )}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

type OwnedItem = Props['items'][number];

/**
 * Your items stacked: copies of one design you hold, and copies of one design
 * on your shelves at one price. Each stack is one shelf.
 */
function stackItems(items: OwnedItem[]) {
    const stacks = new Map<string, OwnedItem[]>();
    for (const item of [...items].sort((a, b) => a.serial - b.serial)) {
        const key = item.listing
            ? `listed|${item.design.id}|${item.listing.price}|${item.listing.currency}`
            : `held|${item.design.id}|${item.resale}`;
        stacks.set(key, [...(stacks.get(key) ?? []), item]);
    }
    return [...stacks.values()];
}

function MyShop({ items, shop }: Props) {
    const { player } = usePage().props;
    const [prices, setPrices] = useState<Record<number, number>>({});
    const [currencies, setCurrencies] = useState<Record<number, Currency>>({});
    const [counts, setCounts] = useState<Record<number, number>>({});
    // Anyone sells looks; only faction members sell recipes, the items with a power.
    const inFaction = player?.faction != null;
    const canSell = (player?.level ?? 1) >= shop.level;

    return (
        <section className="flex flex-col gap-3">
            <p className="text-sm text-muted-foreground">
                {canSell
                    ? `${shop.used} of ${shop.shelves} shelves in use.`
                    : `You get a shop at level ${shop.level}.`}{' '}
                {!inFaction &&
                    'You can sell looks like hats and scarves; recipes, the items with a power, only faction members sell.'}{' '}
                Every sale earns you 3 Rosettes.{' '}
                {shop.applesOnly
                    ? 'Items sell for Apples, which are bought with real money.'
                    : 'Price in Corn, or in Apples, which are bought with real money.'}
                {canSell && !shop.orchard && (
                    <>
                        {' '}
                        <Link href={apples()} className="text-primary">
                            Orchard members
                        </Link>{' '}
                        get one more shelf.
                    </>
                )}
            </p>
            {items.length === 0 ? (
                <p className="text-muted-foreground">
                    You don't own any items yet.
                </p>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2">
                    {stackItems(items).map((stack) => {
                        const item = stack[0];
                        const many = stack.length > 1;
                        const count = Math.min(
                            stack.length,
                            Math.max(1, counts[item.id] ?? stack.length),
                        );

                        return (
                            <li
                                key={item.id}
                                className="flex gap-3 rounded-xl border bg-card p-3"
                            >
                                <ItemPreview design={item.design} />
                                <div className="flex flex-1 flex-col gap-2">
                                    <p className="font-semibold">
                                        {item.design.name}{' '}
                                        {many && (
                                            <span className="rounded-full bg-muted px-1.5 text-xs">
                                                ×{stack.length}
                                            </span>
                                        )}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            #{item.serial}
                                            {many &&
                                                `–${stack[stack.length - 1].serial}`}
                                        </span>
                                    </p>
                                    {item.design.power && (
                                        <PowerLine design={item.design} />
                                    )}
                                    {item.design.power && !item.listing && (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            className="self-start"
                                            onClick={() =>
                                                confirm(
                                                    `Use ${item.design.name}? ${item.design.power} It's used up.`,
                                                ) && act(useItem.url(item.id))
                                            }
                                        >
                                            <Sparkles className="size-4" /> Use
                                            it
                                        </Button>
                                    )}
                                    {item.listing ? (
                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                            {many
                                                ? `${stack.length} on sale`
                                                : 'On sale'}{' '}
                                            for{' '}
                                            {money(
                                                item.listing.price,
                                                item.listing.currency,
                                            )}
                                            {many && ' each'}
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    act(
                                                        unlist.url(
                                                            item.listing!.id,
                                                        ),
                                                        {},
                                                        'delete',
                                                    )
                                                }
                                            >
                                                {many
                                                    ? 'Take one down'
                                                    : 'Take down'}
                                            </Button>
                                            {many && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        act(
                                                            unlist.url(
                                                                item.listing!
                                                                    .id,
                                                                {
                                                                    query: {
                                                                        all: 1,
                                                                    },
                                                                },
                                                            ),
                                                            {},
                                                            'delete',
                                                        )
                                                    }
                                                >
                                                    Take all down
                                                </Button>
                                            )}
                                        </div>
                                    ) : item.design.power && !inFaction ? (
                                        canSell && (
                                            <p className="text-xs text-muted-foreground">
                                                Join a faction to sell this
                                                recipe.
                                            </p>
                                        )
                                    ) : (
                                        canSell && (
                                            <>
                                                {many && (
                                                    <label className="flex items-center gap-2 text-sm">
                                                        How many
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            max={stack.length}
                                                            className="w-20"
                                                            value={count}
                                                            onChange={(e) =>
                                                                setCounts({
                                                                    ...counts,
                                                                    [item.id]:
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                        of {stack.length}
                                                    </label>
                                                )}
                                                <PriceTag
                                                    shop={shop}
                                                    resale={item.resale}
                                                    name={item.design.name}
                                                    price={prices[item.id]}
                                                    currency={
                                                        shop.applesOnly
                                                            ? 'apples'
                                                            : (currencies[
                                                                  item.id
                                                              ] ?? 'corn')
                                                    }
                                                    onPrice={(price) =>
                                                        setPrices({
                                                            ...prices,
                                                            [item.id]: price,
                                                        })
                                                    }
                                                    onCurrency={(currency) =>
                                                        setCurrencies({
                                                            ...currencies,
                                                            [item.id]: currency,
                                                        })
                                                    }
                                                    onList={(price, currency) =>
                                                        act(list.url(item.id), {
                                                            price,
                                                            currency,
                                                            count,
                                                        })
                                                    }
                                                />
                                            </>
                                        )
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

/** Pricing an item for a shelf, in Corn or Apples. */
function PriceTag({
    shop,
    resale,
    name,
    price,
    currency,
    onPrice,
    onCurrency,
    onList,
}: {
    shop: Props['shop'];
    resale: boolean;
    name: string;
    price: number | undefined;
    currency: Currency;
    onPrice: (price: number) => void;
    onCurrency: (currency: Currency) => void;
    onList: (price: number, currency: Currency) => void;
}) {
    const [min, max] = shop.prices[currency];
    const asked = price ?? 0;
    const royalty = resale ? Math.floor((asked * shop.royalty) / 100) : 0;
    const yours = Math.floor((asked * (100 - shop.cut)) / 100) - royalty;

    return (
        <div className="flex flex-col gap-1">
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    type="number"
                    min={min}
                    max={max}
                    className="w-24"
                    placeholder={currency === 'apples' ? 'Apples' : 'Corn'}
                    aria-label={`Price for ${name}`}
                    value={price ?? ''}
                    onChange={(e) => onPrice(Number(e.target.value))}
                />
                {shop.applesOnly ? (
                    <span className="text-sm text-muted-foreground">
                        Apples
                    </span>
                ) : (
                    <NativeSelect
                        value={currency}
                        onChange={(e) => onCurrency(e.target.value as Currency)}
                        aria-label={`Sell ${name} for`}
                        className="w-28"
                    >
                        <option value="corn">Corn</option>
                        <option value="apples">Apples</option>
                    </NativeSelect>
                )}
                <Button
                    size="sm"
                    disabled={asked < min || asked > max}
                    onClick={() => onList(asked, currency)}
                >
                    Put on a shelf
                </Button>
            </div>
            <WhyNot
                reason={
                    (asked < min || asked > max) &&
                    `Type a price first, from ${min.toLocaleString('en-ZA')} to ${max.toLocaleString('en-ZA')} ${currency === 'apples' ? 'Apples' : 'Corn'}.`
                }
            />
            {currency === 'apples' && (
                <p className="text-xs text-muted-foreground">
                    {asked >= min
                        ? `You get ${money(yours, 'apples')} after the game's share${royalty > 0 ? ` and the maker's ${shop.royalty}%` : ''}.`
                        : `Price it from ${min} to ${max.toLocaleString('en-ZA')} Apples.`}
                </p>
            )}
        </div>
    );
}

function Workshop({ designs, workshop }: Props) {
    const { player } = usePage().props;
    const [template, setTemplate] = useState(workshop.templates[0].value);
    const [name, setName] = useState('');
    const [colours, setColours] = useState([
        workshop.palette[0],
        workshop.palette[1],
    ]);
    const [pattern, setPattern] = useState(workshop.patterns[0]);
    const [edition, setEdition] = useState<string>('50');
    const chosen = workshop.templates.find((t) => t.value === template)!;
    const needs = chosen.needs;
    const locked = chosen.creativity > workshop.creativity;
    const groups = [
        { label: 'Looks', for: null },
        { label: 'Powers for you', for: 'you' },
        { label: 'Powers for your faction', for: 'faction' },
    ] as const;

    return (
        <div className="flex flex-col gap-5">
            <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                <h2 className="text-lg font-bold">Design an item</h2>
                {player?.faction == null && (
                    <p className="text-sm text-amber-200">
                        Recipes, the items with a power, belong to faction
                        members. Join a faction to design or make them; anyone
                        can design looks like hats and scarves.
                    </p>
                )}
                <div className="flex flex-wrap items-start gap-4">
                    <ItemPreview
                        design={{
                            template,
                            colours,
                            pattern,
                            name: name || 'Your design',
                        }}
                        size={112}
                    />
                    <div className="grid flex-1 gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm">
                            Shape
                            <NativeSelect
                                value={template}
                                onChange={(e) =>
                                    setTemplate(
                                        e.target
                                            .value as DesignCard['template'],
                                    )
                                }
                            >
                                {groups.map((group) => (
                                    <optgroup
                                        key={group.label}
                                        label={group.label}
                                    >
                                        {workshop.templates
                                            .filter((t) => t.for === group.for)
                                            .map((t) => (
                                                <option
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label}
                                                    {t.creativity >
                                                    workshop.creativity
                                                        ? ` (Creativity ${t.creativity})`
                                                        : ''}
                                                </option>
                                            ))}
                                    </optgroup>
                                ))}
                            </NativeSelect>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Name
                            <Input
                                value={name}
                                maxLength={30}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="Violet Scarf"
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Pattern
                            <NativeSelect
                                value={pattern}
                                onChange={(e) => setPattern(e.target.value)}
                            >
                                {workshop.patterns.map((p) => (
                                    <option key={p} value={p}>
                                        {capitalise(p)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Copies
                            <NativeSelect
                                value={edition}
                                onChange={(e) => setEdition(e.target.value)}
                            >
                                {workshop.editions.map((n) => (
                                    <option key={n} value={n}>
                                        {n} numbered copies
                                    </option>
                                ))}
                                <option value="">Open edition</option>
                            </NativeSelect>
                        </label>
                    </div>
                </div>
                {[0, 1].map((index) => (
                    <div
                        key={index}
                        className="flex flex-wrap items-center gap-2"
                        role="radiogroup"
                        aria-label={
                            index === 0 ? 'Main colour' : 'Second colour'
                        }
                    >
                        <span className="w-28 text-sm">
                            {index === 0 ? 'Main colour' : 'Second colour'}
                        </span>
                        {workshop.palette.map((c) => (
                            <button
                                key={c}
                                type="button"
                                role="radio"
                                aria-checked={colours[index] === c}
                                aria-label={c}
                                onClick={() =>
                                    setColours(
                                        index === 0
                                            ? [c, colours[1]]
                                            : [colours[0], c],
                                    )
                                }
                                className={cn(
                                    'size-7 rounded-full border-2',
                                    colours[index] === c
                                        ? 'border-white'
                                        : 'border-white/10',
                                )}
                                style={{ backgroundColor: c }}
                            />
                        ))}
                    </div>
                ))}
                {chosen.power && (
                    <p className="flex items-start gap-2 rounded-lg border border-primary/40 bg-primary/10 p-3 text-sm">
                        <Sparkles
                            className="mt-0.5 size-4 shrink-0 text-primary"
                            aria-hidden
                        />
                        <span>
                            <span className="font-semibold">
                                {chosen.for === 'you'
                                    ? 'For the one who uses it: '
                                    : "For the user's faction: "}
                            </span>
                            {chosen.power} Each copy is used up when its owner
                            uses it, so makers sell them, and you earn 5% each
                            time one of your copies is resold.
                            {locked &&
                                ` You can design this from Creativity level ${chosen.creativity}; you're at level ${workshop.creativity}. Designing, making and selling grow it.`}
                        </span>
                    </p>
                )}
                <p className="text-sm text-muted-foreground">
                    Each copy needs{' '}
                    {Object.entries(needs)
                        .map(([m, n]) => `${n} ${m}`)
                        .join(' and ')}{' '}
                    and 1 horseshoe. A new maker's first 3 designs get a quick
                    check before copies can be made.
                </p>
                <Button
                    className="self-start"
                    disabled={locked}
                    onClick={() =>
                        act(designRoute.url(), {
                            template,
                            name,
                            colours,
                            pattern,
                            edition: edition === '' ? null : Number(edition),
                        })
                    }
                >
                    Save the design
                </Button>
            </section>

            <section className="flex flex-col gap-2">
                <h2 className="text-lg font-bold">Your designs</h2>
                <p className="text-sm text-muted-foreground">
                    Your materials:{' '}
                    {Object.entries(player?.materials ?? {})
                        .filter(([, n]) => n > 0)
                        .map(([m, n]) => `${n} ${m}`)
                        .join(', ') || 'none yet. Gather them on the map.'}
                </p>
                {designs.length === 0 ? (
                    <p className="text-muted-foreground">None yet.</p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {designs.map((d) => (
                            <li
                                key={d.id}
                                className="flex gap-3 rounded-xl border bg-card p-3"
                            >
                                <ItemPreview design={d} />
                                <div className="flex flex-1 flex-col gap-1">
                                    <p className="font-semibold">{d.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {d.templateLabel} · {d.made}
                                        {d.edition
                                            ? ` of ${d.edition}`
                                            : ''}{' '}
                                        made ·{' '}
                                        {d.status === 'pending'
                                            ? 'waiting for a check'
                                            : d.status === 'rejected'
                                              ? 'not approved'
                                              : 'approved'}
                                    </p>
                                    {d.status === 'approved' && (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            className="mt-auto self-start"
                                            onClick={() => act(craft.url(d.id))}
                                        >
                                            Make a copy (
                                            {Object.entries(d.needs)
                                                .map(([m, n]) => `${n} ${m}`)
                                                .join(', ')}
                                            )
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

/** What a powered item does, and who for. */
