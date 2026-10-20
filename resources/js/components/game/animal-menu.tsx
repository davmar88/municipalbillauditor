import { Link } from '@inertiajs/react';
import { Gift, HandCoins, MessageCircle, UserRound, X } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useState } from 'react';
import { GiftPicker } from '@/components/game/gift-picker';
import { act, speciesEmoji } from '@/lib/game';
import type { Point } from '@/lib/hollowmere';
import { openChat } from '@/lib/pulse';
import { crime, animal as profile } from '@/routes/play';
import type { MapAnimal } from '@/types/game';

const WIDTH = 236;

/**
 * Where a card opened from a tap on the map goes: beside the spot, inside the
 * map, on whichever side has more room, and no taller than that room, so its
 * buttons never fall off the edge.
 */
export function besidePoint(
    at: Point,
    size: { width: number; height: number },
    width: number,
): CSSProperties {
    const left = Math.min(
        Math.max(8, at[0] - width / 2),
        Math.max(8, size.width - width - 8),
    );
    const roomBelow = size.height - at[1] - 22 - 8;
    const roomAbove = at[1] - 22 - 8;

    return roomBelow >= roomAbove
        ? { left, width, top: at[1] + 22, maxHeight: Math.max(120, roomBelow) }
        : {
              left,
              width,
              bottom: size.height - at[1] + 22,
              maxHeight: Math.max(120, roomAbove),
          };
}

/** Closes a map card on Escape. */
export function useEscape(onClose: () => void) {
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);
}

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
    const [giving, setGiving] = useState(false);
    useEscape(onClose);

    return (
        <div
            role="dialog"
            aria-label={animal.name}
            className="absolute z-20 flex flex-col overflow-hidden rounded-xl border bg-card/95 text-sm shadow-xl backdrop-blur"
            style={besidePoint(at, size, WIDTH)}
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
                        onClick={() => setGiving(true)}
                        className="flex items-center gap-2 rounded-lg px-2 py-2 text-left hover:bg-accent"
                    >
                        <Gift className="size-4" /> Send an item
                    </button>
                    {!animal.me && (
                        <button
                            type="button"
                            onClick={() => {
                                // The server says why not, if it can't be done: too far, too soon, a newcomer.
                                act(crime.url(), {
                                    kind: 'rob',
                                    player_id: animal.id,
                                });
                                onClose();
                            }}
                            title="Take a share of the Corn and materials it carries. Stand close; you may be caught."
                            className="flex items-center gap-2 rounded-lg px-2 py-2 text-left text-destructive hover:bg-accent"
                        >
                            <HandCoins className="size-4" /> Rob (2 horseshoes)
                        </button>
                    )}
                </div>
            ) : (
                <div className="flex min-h-0 flex-col p-2">
                    <GiftPicker
                        animal={animal}
                        onBack={() => setGiving(false)}
                    />
                </div>
            )}
        </div>
    );
}
