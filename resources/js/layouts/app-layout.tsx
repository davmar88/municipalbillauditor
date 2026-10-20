import { usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { act } from '@/lib/game';
import { stop } from '@/routes/play-as';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { impersonating, auth } = usePage().props;

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {impersonating && (
                <div className="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-semibold text-black">
                    You're playing as {auth.user?.name}. Everything you do here
                    is done as them.
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => act(stop.url())}
                    >
                        Back to my account
                    </Button>
                </div>
            )}
            {children}
        </AppLayoutTemplate>
    );
}
