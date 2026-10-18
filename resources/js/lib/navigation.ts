import { Coins, Flag, Map, ScrollText, Sun, UserRound } from 'lucide-react';
import { play } from '@/routes';
import { faction, market, me, today, wall } from '@/routes/play';
import type { NavItem } from '@/types';

/** The game's main screens, in the order a visit usually goes. */
export function mainNavigation(missionsWaiting = 0): NavItem[] {
    return [
        { title: 'Today', href: today(), icon: Sun, badge: missionsWaiting },
        { title: 'Map', href: play(), icon: Map },
        { title: 'Wall', href: wall(), icon: ScrollText },
        { title: 'Market', href: market(), icon: Coins },
        { title: 'Faction', href: faction(), icon: Flag },
        { title: 'Me', href: me(), icon: UserRound },
    ];
}
