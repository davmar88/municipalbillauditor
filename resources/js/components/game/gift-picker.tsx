import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { gameJson } from '@/lib/game';
import { gift, gifts } from '@/routes/play';

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
    /** Why you can't give anything just now. */
    cant: string | null;
    message?: string;
};

/**
 * Your bag, to give another animal something from: what can go, how many
 * more you can give today, and a Give button on each. Used from the map's
 * animal menu and from an animal's profile.
 */
export function GiftPicker({
    animal,
    onBack,
}: {
    animal: { id: number; name: string };
    /** Shown as a Back link when the picker sits inside something else. */
    onBack?: () => void;
}) {
    const [bag, setBag] = useState<Bag | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let stopped = false;

        void gameJson<Bag>(gifts.url()).then((result) => {
            if (stopped) {
                return;
            }

            if (result.ok) {
                setBag(result.data);
            } else {
                setError(result.error);
            }
        });

        return () => {
            stopped = true;
        };
    }, []);

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

    return (
        <div className="flex min-h-0 flex-col gap-2">
            <p className="text-xs text-muted-foreground">
                {bag === null
                    ? error === null && 'Opening your bag…'
                    : bag.cant !== null
                      ? bag.cant
                      : bag.left > 0
                        ? `Pick something for ${animal.name}. You can give ${bag.left} more today.`
                        : `You've given ${bag.perDay} items today. You can give more tomorrow.`}
            </p>
            {bag !== null && bag.items.length === 0 && (
                <p className="text-xs">
                    Nothing to give. Items on your shop shelf, and ones you
                    bought with Apples lately, stay with you.
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
                                    busy !== null ||
                                    bag.left === 0 ||
                                    bag.cant !== null
                                }
                                onClick={() => give(item.id)}
                                aria-label={`Give ${item.name} to ${animal.name}`}
                            >
                                {busy === item.id ? '…' : 'Give'}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {error && <p className="text-xs text-red-400">{error}</p>}
            {onBack && (
                <button
                    type="button"
                    onClick={onBack}
                    className="self-start text-xs font-semibold text-muted-foreground hover:underline"
                >
                    Back
                </button>
            )}
        </div>
    );
}
