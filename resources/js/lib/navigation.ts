import { Coins, Flag, Map, ScrollText, UserRound } from 'lucide-react';
import { play } from '@/routes';
import { faction, market, me, wall } from '@/routes/play';
import type { NavItem } from '@/types';

/** The game's five screens, in the order a visit usually goes. */
export function mainNavigation(): NavItem[] {
    return [
        { title: 'Map', href: play(), icon: Map },
        { title: 'Wall', href: wall(), icon: ScrollText },
        { title: 'Market', href: market(), icon: Coins },
        { title: 'Faction', href: faction(), icon: Flag },
        { title: 'Me', href: me(), icon: UserRound },
    ];
}
