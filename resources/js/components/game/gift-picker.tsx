import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { gameJson } from '@/lib/game';
import { gift, gifts, send } from '@/routes/play';

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

/**
 * Sending Corn, Apples, Pineapples or a material to another animal, a
 * little a day: Apples sent wait before the other animal can cash them out.
 */
export function SendPicker({
    animal,
}: {
    animal: { id: number; name: string };
}) {
    const { player } = usePage().props;
    const materials = Object.entries(
        (player?.materials ?? {}) as Record<string, number>,
    ).filter(([, amount]) => amount > 0);
    const options: [string, string, number][] = [
        ['corn', 'Corn', player?.corn ?? 0],
        ['apples', 'Apples', player?.apples ?? 0],
        ['pineapples', 'Pineapples', player?.pineapples ?? 0],
        ...materials.map(([name, amount]): [string, string, number] => [
            name,
            name,
            amount,
        ]),
    ];
    const [what, setWhat] = useState('corn');
    const [amount, setAmount] = useState(10);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const have = options.find(([value]) => value === what)?.[2] ?? 0;

    const go = async () => {
        setBusy(true);
        setError(null);
        const result = await gameJson<{ message: string }>(
            send.url(animal.id),
            'POST',
            { what, amount },
        );
        setBusy(false);

        if (result.ok) {
            toast.success(result.data.message);
            router.reload({ only: ['player'] });
        } else {
            setError(result.error);
        }
    };

    return (
        <div className="flex flex-col gap-2 border-t pt-2 text-sm">
            <p className="text-xs font-semibold">
                Or send {animal.name} something else
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <select
                    value={what}
                    onChange={(e) => setWhat(e.target.value)}
                    className="rounded-md border bg-background px-2 py-1"
                    aria-label="What to send"
                >
                    {options.map(([value, label, count]) => (
                        <option key={value} value={value}>
                            {label} ({count.toLocaleString('en-ZA')})
                        </option>
                    ))}
                </select>
                <input
                    type="number"
                    min={1}
                    max={Math.max(1, have)}
                    value={amount}
                    onChange={(e) =>
                        setAmount(Math.max(1, Number(e.target.value) || 1))
                    }
                    className="w-24 rounded-md border bg-background px-2 py-1"
                    aria-label="How many"
                />
                <Button
                    size="sm"
                    disabled={busy || have < amount}
                    onClick={() => void go()}
                >
                    Send
                </Button>
            </div>
            {have < amount && (
                <p className="text-xs text-muted-foreground">
                    You have {have.toLocaleString('en-ZA')}.
                </p>
            )}
            {error && <p className="text-xs text-destructive">{error}</p>}
            <p className="text-xs text-muted-foreground">
                There's a daily limit on each. Apples you send wait a while
                before {animal.name} can cash them out.
            </p>
        </div>
    );
}
