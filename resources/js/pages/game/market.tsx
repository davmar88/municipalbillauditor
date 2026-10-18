import { Head, Link, usePage } from '@inertiajs/react';
import { Hammer, ShoppingBasket, Store } from 'lucide-react';
import { useState } from 'react';
import { GameBar } from '@/components/game/game-bar';
import { ItemPreview } from '@/components/game/item-preview';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { act, capitalise } from '@/lib/game';
import { cn } from '@/lib/utils';
import { buy, craft, design as designRoute, list, unlist } from '@/routes/play';
import { show as conversation } from '@/routes/play/messages';
import type { DesignCard } from '@/types';

type Props = {
    listings: {
        id: number;
        price: number;
        serial: number;
        design: DesignCard;
        seller: {
            id: number;
            name: string;
            faction: string | null;
            colour: string | null;
            closed: boolean;
        };
        mine: boolean;
    }[];
    levy: number;
    bannedFaction: number | null;
    items: {
        id: number;
        serial: number;
        design: DesignCard;
        listing: { id: number; price: number } | null;
    }[];
    designs: (DesignCard & { needs: Record<string, number> })[];
    workshop: {
        templates: {
            value: DesignCard['template'];
            label: string;
            needs: Record<string, number>;
        }[];
        palette: string[];
        patterns: string[];
        editions: number[];
    };
    shop: {
        level: number;
        shelves: number;
        used: number;
        minPrice: number;
        maxPrice: number;
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
                        Everything here is made by players. Prices are in Corn
                        {props.levy > 0 &&
                            `, plus the House's ${props.levy}% market levy`}
                        . Real money comes after the test season.
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
                {tab === 'browse' && <Browse {...props} />}
                {tab === 'shop' && <MyShop {...props} />}
                {tab === 'workshop' && <Workshop {...props} />}
            </div>
        </>
    );
}

function Browse({ listings, levy }: Props) {
    if (listings.length === 0) {
        return (
            <p className="text-muted-foreground">
                The shelves are empty. Design something in the Workshop and be
                the first to sell.
            </p>
        );
    }

    return (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {listings.map((listing) => (
                <li
                    key={listing.id}
                    className="flex gap-3 rounded-xl border bg-card p-3"
                >
                    <ItemPreview design={listing.design} />
                    <div className="flex flex-1 flex-col gap-1">
                        <p className="font-semibold">{listing.design.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {listing.design.templateLabel} #{listing.serial}
                            {listing.design.edition &&
                                ` of ${listing.design.edition}`}{' '}
                            · by{' '}
                            {listing.mine ? (
                                <span
                                    style={
                                        listing.seller.colour
                                            ? { color: listing.seller.colour }
                                            : undefined
                                    }
                                >
                                    {listing.seller.name}
                                </span>
                            ) : (
                                <Link
                                    href={conversation(listing.seller.id)}
                                    className="no-underline hover:underline"
                                    style={
                                        listing.seller.colour
                                            ? { color: listing.seller.colour }
                                            : undefined
                                    }
                                    title={`Write to ${listing.seller.name}`}
                                >
                                    {listing.seller.name}
                                </Link>
                            )}
                        </p>
                        <p className="text-sm font-semibold">
                            {listing.price} Corn
                            {levy > 0 && (
                                <span className="font-normal text-muted-foreground">
                                    {' '}
                                    + {Math.floor(
                                        (listing.price * levy) / 100,
                                    )}{' '}
                                    levy
                                </span>
                            )}
                        </p>
                        {listing.mine ? (
                            <span className="text-xs text-primary">
                                Your listing
                            </span>
                        ) : listing.seller.closed ? (
                            <span className="text-xs text-muted-foreground">
                                Shop closed while the home is rebuilt
                            </span>
                        ) : (
                            <Button
                                size="sm"
                                className="mt-auto self-start"
                                onClick={() => act(buy.url(listing.id))}
                            >
                                Buy
                            </Button>
                        )}
                    </div>
                </li>
            ))}
        </ul>
    );
}

function MyShop({ items, shop }: Props) {
    const { player } = usePage().props;
    const [prices, setPrices] = useState<Record<number, number>>({});
    const canSell = (player?.level ?? 1) >= shop.level;

    return (
        <section className="flex flex-col gap-3">
            <p className="text-sm text-muted-foreground">
                {canSell
                    ? `${shop.used} of ${shop.shelves} shelves in use.`
                    : `You get a shop at level ${shop.level}.`}{' '}
                Every sale earns you 3 Rosettes.
            </p>
            {items.length === 0 ? (
                <p className="text-muted-foreground">
                    You don't own any items yet.
                </p>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="flex gap-3 rounded-xl border bg-card p-3"
                        >
                            <ItemPreview design={item.design} />
                            <div className="flex flex-1 flex-col gap-2">
                                <p className="font-semibold">
                                    {item.design.name}{' '}
                                    <span className="text-xs text-muted-foreground">
                                        #{item.serial}
                                    </span>
                                </p>
                                {item.listing ? (
                                    <div className="flex items-center gap-2 text-sm">
                                        On sale for {item.listing.price} Corn
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
                                            Take down
                                        </Button>
                                    </div>
                                ) : (
                                    canSell && (
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="number"
                                                min={shop.minPrice}
                                                max={shop.maxPrice}
                                                className="w-28"
                                                placeholder="Corn"
                                                value={prices[item.id] ?? ''}
                                                onChange={(e) =>
                                                    setPrices({
                                                        ...prices,
                                                        [item.id]: Number(
                                                            e.target.value,
                                                        ),
                                                    })
                                                }
                                            />
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    act(list.url(item.id), {
                                                        price:
                                                            prices[item.id] ??
                                                            0,
                                                    })
                                                }
                                            >
                                                Put on a shelf
                                            </Button>
                                        </div>
                                    )
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
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
    const needs = workshop.templates.find((t) => t.value === template)!.needs;

    return (
        <div className="flex flex-col gap-5">
            <section className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                <h2 className="text-lg font-bold">Design an item</h2>
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
                                {workshop.templates.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
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
