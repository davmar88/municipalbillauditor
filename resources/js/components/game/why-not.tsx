import { Info } from 'lucide-react';

/**
 * Why a button can't be pressed yet, and what it still needs: shown right
 * under the greyed-out button, so nobody is left wondering.
 */
export function WhyNot({
    reason,
}: {
    reason: string | null | false | undefined;
}) {
    if (!reason) {
        return null;
    }

    return (
        <p
            role="note"
            className="flex basis-full items-start gap-1.5 text-xs text-amber-200/90"
        >
            <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            <span>{reason}</span>
        </p>
    );
}

/** "You need 3 horseshoes and have 1": for buttons that cost horseshoes. */
export function needHorseshoes(have: number, need: number): string | null {
    if (have >= need) {
        return null;
    }

    return `You need ${need} ${need === 1 ? 'horseshoe' : 'horseshoes'} and have ${have}. One comes back every 45 minutes and you're filled up to 500 every 24 hours, or buy some with Apples on the Apples page, or eat a hot meal at the Black Dog in Irongate.`;
}

/** "You need 40 Corn and have 25": for buttons that cost the player's own Corn. */
export function needCorn(have: number, need: number): string | null {
    if (have >= need) {
        return null;
    }

    return `You need ${need} Corn and have ${have}. Gather Corn in the fields, or finish today's jobs.`;
}

/** What a faction's store is short of, and how members fill it. */
export function storeShort(short: string[]): string | null {
    if (short.length === 0) {
        return null;
    }

    return `Your faction's store is short of ${list(short)}. Give what you carry with the buttons here, or from the Give box on the Faction page.`;
}

/** "a, b and c". */
export function list(items: string[]): string {
    return items.length > 1
        ? `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`
        : (items[0] ?? '');
}
