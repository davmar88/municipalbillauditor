import { Link, usePage } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { mainNavigation } from '@/lib/navigation';
import { cn } from '@/lib/utils';

/** Bottom tabs on phones, where most of the game is played. */
export function MobileTabBar() {
    const { player, missionsWaiting } = usePage().props;
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    if (!player) {
        return null;
    }

    return (
        <nav
            aria-label="Main"
            className="fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
        >
            <ul className="flex">
                {mainNavigation(missionsWaiting).map((item) => {
                    // The map is /play itself; the other screens live under it.
                    const active =
                        item.title === 'Map'
                            ? isCurrentUrl(item.href)
                            : isCurrentOrParentUrl(item.href);

                    return (
                        <li key={item.title} className="flex-1">
                            <Link
                                href={item.href}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] font-semibold',
                                    active
                                        ? 'text-primary'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {item.icon && (
                                    <item.icon className="size-5" aria-hidden />
                                )}
                                {item.title}
                                {!!item.badge && (
                                    <span
                                        className="absolute top-1.5 left-[calc(50%+6px)] min-w-4 rounded-full bg-primary px-1 text-[10px] leading-4 text-primary-foreground"
                                        aria-label={` waiting`}
                                    >
                                        {item.badge}
                                    </span>
                                )}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
