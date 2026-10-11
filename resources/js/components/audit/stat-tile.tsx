import type { ReactNode } from 'react';

/**
 * A headline figure. Figures use proportional digits here: they stand alone
 * rather than lining up in a column.
 */
export function StatTile({
    label,
    value,
    detail,
    tone = 'default',
}: {
    label: string;
    value: ReactNode;
    detail?: ReactNode;
    tone?: 'default' | 'flag';
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border bg-card p-5">
            <span className="text-sm text-muted-foreground">{label}</span>
            <span
                className={`text-[32px] leading-10 font-bold tracking-tight ${tone === 'flag' ? 'text-flag-ink' : ''}`}
            >
                {value}
            </span>
            {detail && (
                <span className="text-sm text-muted-foreground">{detail}</span>
            )}
        </div>
    );
}
