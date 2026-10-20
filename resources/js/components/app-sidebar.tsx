import { Link, usePage } from '@inertiajs/react';
import {
    Apple,
    Banknote,
    Crosshair,
    Hammer,
    KeyRound,
    Mail,
    MessagesSquare,
    Puzzle,
    ShieldAlert,
    Trophy,
    Mountain,
} from 'lucide-react';
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
import { useCurrentUrl } from '@/hooks/use-current-url';
import { mainNavigation } from '@/lib/navigation';
import { admin, play, puzzle } from '@/routes';
import { cashouts, valleys } from '@/routes/admin';
import {
    apples,
    cashout,
    chat,
    messages,
    rankings,
    wanted,
} from '@/routes/play';
import { designs, reports } from '@/routes/staff';
import type { NavItem } from '@/types';

const moreNavItems: NavItem[] = [
    { title: 'Chat', href: chat(), icon: MessagesSquare },
    { title: 'Spot the Edit', href: puzzle(), icon: Puzzle },
    { title: 'Rankings', href: rankings(), icon: Trophy },
    { title: 'Wanted', href: wanted(), icon: Crosshair },
    { title: 'Apples', href: apples(), icon: Apple },
    { title: 'Cash out', href: cashout(), icon: Banknote },
];

const staffNavItems: NavItem[] = [
    { title: 'Designs to check', href: designs(), icon: Hammer },
    { title: 'Reported messages', href: reports(), icon: ShieldAlert },
];

export function AppSidebar() {
    const {
        player,
        isStaff,
        isAdmin,
        cashoutsWaiting,
        missionsWaiting,
        unreadMessages,
    } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const messagesNavItem: NavItem = {
        title: 'Messages',
        href: messages(),
        icon: Mail,
        badge: unreadMessages,
    };

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
                {player && <NavMain items={mainNavigation(missionsWaiting)} />}
                {player && (
                    <NavMain
                        label="More"
                        items={[messagesNavItem, ...moreNavItems]}
                    />
                )}
                {isStaff && <NavMain label="Our team" items={staffNavItems} />}
                {isAdmin && (
                    <NavMain
                        label="Admin"
                        items={[
                            {
                                title: 'Payments and keys',
                                href: admin(),
                                icon: KeyRound,
                                // Not lit up on the Cash-outs page under it.
                                isActive: isCurrentUrl(admin()),
                            },
                            {
                                title: 'Cash-outs',
                                href: cashouts(),
                                icon: Banknote,
                                badge: cashoutsWaiting,
                            },
                            {
                                title: 'Valleys',
                                href: valleys(),
                                icon: Mountain,
                            },
                        ]}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
