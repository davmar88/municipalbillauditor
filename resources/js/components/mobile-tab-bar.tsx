import { Link, usePage } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { mainNavigation } from '@/lib/navigation';
import { cn } from '@/lib/utils';

/**
 * Bottom tabs on phones, where the bill is usually photographed and the
 * tracker checked.
 */
export function MobileTabBar() {
    const { organisation } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const items = mainNavigation(organisation?.kind);

    if (!organisation) {
        return null;
    }

    return (
        <nav
            aria-label="Main"
            className="fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
        >
            <ul className="flex">
                {items.map((item) => {
                    const active = isCurrentOrParentUrl(item.href);

                    return (
                        <li key={item.title} className="flex-1">
                            <Link
                                href={item.href}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-semibold',
                                    active
                                        ? 'text-primary'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {item.icon && (
                                    <item.icon className="size-5" aria-hidden />
                                )}
                                {item.title}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
