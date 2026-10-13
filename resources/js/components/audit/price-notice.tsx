import { Link } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { cn } from '@/lib/utils';
import { index as credit } from '@/routes/credit';
import type { JobQuote } from '@/types';

/**
 * What a job costs, shown before it runs: included in a monthly plan, paid
 * from credit, or waiting for a top-up.
 */
export function PriceNotice({
    quote,
    action,
    whenShort,
    className,
}: {
    quote: JobQuote;
    action: string;
    whenShort?: string;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'flex items-start gap-2 rounded-md px-4 py-3 text-sm',
                quote.affordable
                    ? 'bg-muted text-foreground'
                    : 'bg-action-soft text-action-ink',
                className,
            )}
        >
            <Wallet className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span>
                {action} costs{' '}
                <span className="figures font-semibold">{quote.price}</span>{' '}
                from your prepaid credit. Your balance is{' '}
                <span className="figures">{quote.balance}</span>.
                {!quote.affordable && (
                    <>
                        {' '}
                        {whenShort}{' '}
                        <Link
                            href={credit()}
                            className="font-semibold underline underline-offset-[3px]"
                        >
                            Top up
                        </Link>
                    </>
                )}
            </span>
        </p>
    );
}
