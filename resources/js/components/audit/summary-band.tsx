import type { ReactNode } from 'react';

/** The dark band of headline figures at the top of a bill or claim. */
export function SummaryBand({
    items,
    label = 'Summary',
}: {
    items: { label: string; value: ReactNode }[];
    label?: string;
}) {
    return (
        <section
            aria-label={label}
            className="flex flex-wrap items-stretch rounded-lg bg-panel text-panel-foreground"
        >
            {items.map((item, index) => (
                <div
                    key={item.label}
                    className={`flex min-w-[200px] flex-1 flex-col gap-0.5 px-5 py-3.5 ${index < items.length - 1 ? 'border-b border-white/15 sm:border-r sm:border-b-0' : ''}`}
                >
                    <span className="text-[13px] leading-[18px] text-panel-muted">
                        {item.label}
                    </span>
                    <span className="text-xl leading-7 font-semibold">
                        {item.value}
                    </span>
                </div>
            ))}
        </section>
    );
}
