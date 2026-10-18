import { Link, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { act, money } from '@/lib/game';
import { apples, buy } from '@/routes/play';
import type { DesignCard } from '@/types';
import type { Currency } from '@/types/game';

type Listing = { id: number; price: number; currency: Currency };

/** The House's market levy on a Corn price, in Corn. */
function levyOn(listing: Listing, levy: number): number {
    return listing.currency === 'corn'
        ? Math.floor((listing.price * levy) / 100)
        : 0;
}

/** A shop price, with the market levy a Corn sale pays on top. */
export function ListingPrice({
    listing,
    levy,
}: {
    listing: Listing;
    levy: number;
}) {
    return (
        <p className="text-sm font-semibold">
            {listing.currency === 'apples' && <span aria-hidden>🍎 </span>}
            {money(listing.price, listing.currency)}
            {levyOn(listing, levy) > 0 && (
                <span className="font-normal text-muted-foreground">
                    {' '}
                    + {levyOn(listing, levy)} levy
                </span>
            )}
        </p>
    );
}

/** Buy an item from someone's shop, or see what you're short of. */
export function BuyButton({
    listing,
    levy,
}: {
    listing: Listing;
    levy: number;
}) {
    const { player } = usePage().props;
    const held: Record<Currency, number> = {
        corn: player?.corn ?? 0,
        apples: player?.apples ?? 0,
    };
    const short =
        listing.price + levyOn(listing, levy) - held[listing.currency];

    if (short > 0 && listing.currency === 'apples') {
        return (
            <Button
                asChild
                size="sm"
                variant="secondary"
                className="mt-auto self-start"
            >
                <Link href={apples()}>Get {money(short, 'apples')}</Link>
            </Button>
        );
    }

    return (
        <Button
            size="sm"
            className="mt-auto self-start"
            disabled={short > 0}
            title={short > 0 ? `You need ${short} more Corn` : undefined}
            onClick={() => act(buy.url(listing.id))}
        >
            {short > 0 ? `Need ${short} more Corn` : 'Buy'}
        </Button>
    );
}

/** What using a copy of the design does. */
export function PowerLine({ design }: { design: DesignCard }) {
    return (
        <p className="flex items-start gap-1.5 text-xs text-primary">
            <Sparkles className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            <span>
                {design.for === 'faction' && (
                    <span className="font-semibold">For a faction: </span>
                )}
                {design.power}
            </span>
        </p>
    );
}
