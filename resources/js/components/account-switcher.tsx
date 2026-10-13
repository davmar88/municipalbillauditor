import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus, Settings2 } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { index as accounts, switchMethod } from '@/routes/accounts';

/**
 * The account being worked in, and every other one to switch to: a home, a
 * rental portfolio, a business. Each has its own credit and bills.
 */
export function AccountSwitcher() {
    const { organisation, organisations } = usePage().props;
    const cleanup = useMobileNavigation();

    if (!organisation) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="mt-2 flex w-full items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left outline-none group-data-[collapsible=icon]:hidden hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-sidebar-ring data-[state=open]:bg-white/10"
                    data-test="account-switcher"
                >
                    <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-sm leading-5 font-semibold text-sidebar-accent-foreground">
                            {organisation.name}
                        </span>
                        <span className="text-xs leading-4 text-sidebar-foreground/80">
                            {organisation.kindLabel}
                        </span>
                    </span>
                    <ChevronsUpDown
                        className="size-4 shrink-0 text-sidebar-foreground/80"
                        aria-hidden
                    />
                    <span className="sr-only">Switch account</span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                align="start"
            >
                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                    Your accounts
                </DropdownMenuLabel>
                {organisations.map((account) => {
                    const current = account.id === organisation.id;

                    return (
                        <DropdownMenuItem
                            key={account.id}
                            disabled={current}
                            className="flex items-start gap-2 data-[disabled]:opacity-100"
                            onSelect={() => {
                                cleanup();
                                router.post(switchMethod(account.id).url);
                            }}
                        >
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate font-medium">
                                    {account.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {account.kindLabel}
                                </span>
                            </span>
                            {current && (
                                <Check
                                    className="mt-0.5 size-4 text-primary"
                                    aria-label="Current account"
                                />
                            )}
                        </DropdownMenuItem>
                    );
                })}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        href={accounts({ query: { add: 1 } })}
                        onClick={cleanup}
                    >
                        <Plus className="mr-2" />
                        Add an account
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={accounts()} onClick={cleanup}>
                        <Settings2 className="mr-2" />
                        Manage accounts
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
