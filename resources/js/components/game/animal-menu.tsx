import { Link } from '@inertiajs/react';
import { Gift, MessageCircle, UserRound, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { gameJson, speciesEmoji } from '@/lib/game';
import type { Point } from '@/lib/hollowmere';
import { openChat } from '@/lib/pulse';
import { animal as profile, gift, gifts } from '@/routes/play';
import type { MapAnimal } from '@/types/game';

type Bag = {
    items: {
        id: number;
        serial: number;
        name: string;
        kind: string;
        colours: string[];
        pattern: string;
    }[];
    left: number;
    perDay: number;
    message?: string;
};

const WIDTH = 236;

/**
 * What you can do with an animal you tap on the map: see its profile, write
 * to it, or give it something from your bag. It opens by the animal.
 */
export function AnimalMenu({
    animal,
    at,
    size,
    wanted,
    onClose,
}: {
    animal: MapAnimal;
    /** Where on the map it was tapped, in pixels from the map's corner. */
    at: Point;
    size: { width: number; height: number };
    wanted: boolean;
    onClose: () => void;
}) {
    const [bag, setBag] = useState<Bag | null>(null);
    const [giving, setGiving] = useState(false);
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const openBag = async () => {
        setGiving(true);
        setError(null);
        const result = await gameJson<Bag>(gifts.url());

        if (result.ok) {
            setBag(result.data);
        } else {
            setError(result.error);
        }
    };

    const give = async (itemId: number) => {
        setBusy(itemId);
        setError(null);
        const result = await gameJson<Bag>(gift.url(animal.id), 'POST', {
            item: itemId,
        });
        setBusy(null);

        if (result.ok) {
            setBag(result.data);
            toast.success(result.data.message ?? 'Given.');
        } else {
            setError(result.error);
        }
    };

    // Beside the animal, kept inside the map.
    const left = Math.min(
        Math.max(8, at[0] - WIDTH / 2),
        Math.max(8, size.width - WIDTH - 8),
    );
    const below = at[1] < size.height * 0.55;
    const style = below
        ? { left, top: Math.min(at[1] + 22, size.height - 60) }
        : { left, bottom: Math.max(8, size.height - at[1] + 22) };

    return (
        <div
            role="dialog"
            aria-label={animal.name}
            className="absolute z-20 flex max-h-[60%] flex-col overflow-hidden rounded-xl border bg-card/95 text-sm shadow-xl backdrop-blur"
            style={{ ...style, width: WIDTH }}
            onPointerDown={(event) => event.stopPropagation()}
        >
            <div className="flex items-center gap-2 border-b px-3 py-2">
                <span className="text-xl" aria-hidden>
                    {speciesEmoji(animal.species)}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-bold">
                        {animal.name}
                    </span>
                    {wanted && (
                        <span className="text-xs font-semibold text-[#f5a27a]">
                            🎯 Wanted
                        </span>
                    )}
                </span>
                <button
                    type="button"
                    aria-label="Close"
                    onClick={onClose}
                    className="rounded-md p-1 text-muted-foreground hover:bg-accent"
                >
                    <X className="size-4" />
                </button>
            </div>

            {!giving ? (
                <div className="flex flex-col p-1.5">
                    <Link
                        href={profile(animal.id)}
                        className="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-accent"
                    >
                        <UserRound className="size-4" /> Open profile
                    </Link>
                    <button
                        type="button"
                        onClick={() => {
                            openChat(animal.id);
                            onClose();
                        }}
                        className="flex items-center gap-2 rounded-lg px-2 py-2 text-left hover:bg-accent"
                    >
                        <MessageCircle className="size-4" /> Message
                    </button>
                    <button
                        type="button"
                        onClick={openBag}
                        className="flex items-center gap-2 rounded-lg px-2 py-2 text-left hover:bg-accent"
                    >
                        <Gift className="size-4" /> Send an item
                    </button>
                </div>
            ) : (
                <div className="flex min-h-0 flex-col gap-2 p-2">
                    <p className="text-xs text-muted-foreground">
                        {bag === null
                            ? 'Opening your bag…'
                            : bag.left > 0
                              ? `Pick something for ${animal.name}. You can give ${bag.left} more today.`
                              : `You've given ${bag.perDay} items today. You can give more tomorrow.`}
                    </p>
                    {bag !== null && bag.items.length === 0 && (
                        <p className="text-xs">
                            Nothing to give: items on your shop shelf stay
                            there.
                        </p>
                    )}
                    {bag !== null && bag.items.length > 0 && (
                        <ul className="flex min-h-0 flex-col gap-1 overflow-y-auto">
                            {bag.items.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-center gap-2 rounded-lg px-1 py-1"
                                >
                                    <span
                                        className="size-4 shrink-0 rounded-full border"
                                        style={{
                                            background: `linear-gradient(135deg, ${item.colours.join(', ')})`,
                                        }}
                                        aria-hidden
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold">
                                            {item.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {item.kind} · No. {item.serial}
                                        </span>
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        disabled={
                                            busy !== null || bag.left === 0
                                        }
                                        onClick={() => give(item.id)}
                                    >
                                        {busy === item.id ? '…' : 'Give'}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {error && <p className="text-xs text-red-400">{error}</p>}
                    <button
                        type="button"
                        onClick={() => setGiving(false)}
                        className="self-start text-xs font-semibold text-muted-foreground hover:underline"
                    >
                        Back
                    </button>
                </div>
            )}
        </div>
    );
}
