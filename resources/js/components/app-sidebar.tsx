import { Link, usePage } from '@inertiajs/react';
import { Database, ShieldCheck } from 'lucide-react';
import { AccountSwitcher } from '@/components/account-switcher';
import AppLogo from '@/components/app-logo';
import { CopyButton } from '@/components/copy-button';
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
import { dashboard } from '@/routes';
import { index as staffIndex, reference } from '@/routes/staff';
import type { NavItem } from '@/types';
import { AddressText } from '@/components/address-text';

const staffNavItems: NavItem[] = [
    { title: 'Work queue', href: staffIndex(), icon: ShieldCheck },
    { title: 'Reference data', href: reference(), icon: Database },
];

export function AppSidebar() {
    const { organisation, isStaff } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <AccountSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavigation(organisation?.kind)} />
                {isStaff && <NavMain label="Our team" items={staffNavItems} />}
            </SidebarContent>

            <SidebarFooter>
                {organisation && (
                    <div className="flex flex-col gap-1 rounded-lg border border-white/10 bg-white/5 px-3 py-3 group-data-[collapsible=icon]:hidden">
                        <span className="text-[13px] leading-[18px] text-sidebar-foreground/80">
                            Forward bills to
                        </span>
                        <span className="font-mono text-[12.5px] leading-[18px] [overflow-wrap:anywhere] text-sidebar-accent-foreground">
                            <AddressText
                                address={organisation.forwardingAddress}
                            />
                        </span>
                        <CopyButton
                            value={organisation.forwardingAddress}
                            label="Copy address"
                            className="mt-1 self-start text-sidebar-foreground hover:bg-white/10 hover:text-white"
                        />
                    </div>
                )}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
