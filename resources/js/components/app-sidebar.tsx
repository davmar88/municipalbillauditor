import { Link, usePage } from '@inertiajs/react';
import { Hammer, MessagesSquare, Puzzle } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { mainNavigation } from '@/lib/navigation';
import { play, puzzle } from '@/routes';
import { chat } from '@/routes/play';
import { designs } from '@/routes/staff';
import type { NavItem } from '@/types';

const moreNavItems: NavItem[] = [
    { title: 'Chat', href: chat(), icon: MessagesSquare },
    { title: 'Spot the Edit', href: puzzle(), icon: Puzzle },
];

const staffNavItems: NavItem[] = [
    { title: 'Designs to check', href: designs(), icon: Hammer },
];

export function AppSidebar() {
    const { player, isStaff } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={play()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {player && (
                    <div className="mt-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 group-data-[collapsible=icon]:hidden">
                        <p className="truncate text-sm leading-5 font-semibold text-sidebar-accent-foreground">
                            {player.name}
                        </p>
                        <p className="text-xs leading-4 text-sidebar-foreground/80">
                            {player.faction ? player.faction.name : 'Lone wolf'}
                        </p>
                    </div>
                )}
            </SidebarHeader>

            <SidebarContent>
                {player && <NavMain items={mainNavigation()} />}
                {player && <NavMain label="More" items={moreNavItems} />}
                {isStaff && <NavMain label="Our team" items={staffNavItems} />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
