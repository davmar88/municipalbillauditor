import type { ReactNode } from 'react';

export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
}: {
    eyebrow?: ReactNode;
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-end justify-between gap-4">
            <div className="flex min-w-0 flex-col gap-1.5">
                {eyebrow && (
                    <div className="text-sm text-muted-foreground">
                        {eyebrow}
                    </div>
                )}
                <h1 className="text-[28px] leading-9 font-bold tracking-tight sm:text-[32px]">
                    {title}
                </h1>
                {description && (
                    <div className="text-muted-foreground">{description}</div>
                )}
            </div>
            {actions && <div className="flex flex-wrap gap-2.5">{actions}</div>}
        </header>
    );
}

/** The padded column every app page sits in. */
export function PageBody({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`flex flex-1 flex-col gap-6 px-4 pt-6 pb-10 sm:px-8 ${className}`}
        >
            {children}
        </div>
    );
}
