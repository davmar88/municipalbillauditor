import { Link, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { messages } from '@/routes/play';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { player, unreadMessages } = usePage().props;

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-3" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {player && (
                <Link
                    href={messages()}
                    prefetch
                    className="relative ml-auto flex size-10 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                    aria-label={
                        unreadMessages > 0
                            ? `Messages, ${unreadMessages} unread`
                            : 'Messages'
                    }
                >
                    <Mail className="size-5" aria-hidden />
                    {unreadMessages > 0 && (
                        <span
                            className="absolute top-0.5 right-0 min-w-5 rounded-full bg-primary px-1 text-center text-[11px] leading-5 font-bold text-primary-foreground"
                            aria-hidden
                        >
                            {unreadMessages > 99 ? '99+' : unreadMessages}
                        </span>
                    )}
                </Link>
            )}
        </header>
    );
}
