import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';
import type { BillSummary, Confidence, PortfolioState } from '@/types';

/**
 * Status pills. Orange means something is wrong or needs attention, blue
 * means an action or progress, grey means nothing to do.
 */
const chipVariants = cva(
    'inline-flex shrink-0 items-center gap-1 rounded-full text-[13px] leading-[18px] font-semibold whitespace-nowrap',
    {
        variants: {
            tone: {
                flag: 'bg-flag-soft px-2.5 py-0.5 text-flag-ink',
                'flag-outline':
                    'border border-flag px-[9px] py-px text-flag-ink',
                action: 'bg-action-soft px-2.5 py-0.5 text-action-ink',
                neutral: 'bg-muted px-2.5 py-0.5 text-foreground/80',
                missing:
                    'border border-dashed border-muted-foreground px-[9px] py-px text-muted-foreground',
            },
        },
        defaultVariants: {
            tone: 'neutral',
        },
    },
);

export type ChipTone = NonNullable<VariantProps<typeof chipVariants>['tone']>;

export function Chip({
    tone,
    className,
    ...props
}: ComponentProps<'span'> & VariantProps<typeof chipVariants>) {
    return (
        <span className={cn(chipVariants({ tone }), className)} {...props} />
    );
}

export function confidenceTone(confidence: Confidence): ChipTone {
    switch (confidence) {
        case 'likely':
            return 'flag';
        case 'possible':
            return 'flag-outline';
        default:
            return 'action';
    }
}

export function portfolioTone(state: PortfolioState): ChipTone {
    switch (state) {
        case 'flags':
            return 'flag';
        case 'escalated':
            return 'flag-outline';
        case 'in_dispute':
            return 'action';
        case 'missing':
            return 'missing';
        default:
            return 'neutral';
    }
}

/** The one-word state of a bill in a list. */
export function billChip(bill: BillSummary): { tone: ChipTone; label: string } {
    if (bill.dispute?.submitted) {
        return { tone: 'action', label: bill.dispute.stageLabel };
    }

    if (bill.status !== 'checked') {
        return {
            tone:
                bill.status === 'reading' || bill.status === 'received'
                    ? 'neutral'
                    : 'action',
            label: bill.statusLabel,
        };
    }

    if (bill.flags > 0) {
        return {
            tone: 'flag',
            label: bill.flags === 1 ? '1 flag' : `${bill.flags} flags`,
        };
    }

    return { tone: 'neutral', label: 'All clear' };
}
