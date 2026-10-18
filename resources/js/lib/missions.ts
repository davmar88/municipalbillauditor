import type { InertiaLinkProps } from '@inertiajs/react';
import { play } from '@/routes';
import { chat, faction, market } from '@/routes/play';

type Place = { label: string; href: NonNullable<InertiaLinkProps['href']> };

/** Where in the game a mission is done, so its card can take you there. */
export function missionPlace(key: string): Place {
    if (['arrival.hello', 'daily.chat'].includes(key)) {
        return { label: 'Go to chat', href: chat() };
    }

    if (['herd.belong', 'daily.give', 'daily.crime'].includes(key)) {
        return { label: 'Go to Faction', href: faction() };
    }

    if (
        key.startsWith('living.') ||
        [
            'power.buyer',
            'power.mogul',
            'daily.design',
            'daily.craft',
            'daily.sell',
            'daily.buy',
        ].includes(key)
    ) {
        return { label: 'Go to the market', href: market() };
    }

    return { label: 'Go to the map', href: play() };
}
