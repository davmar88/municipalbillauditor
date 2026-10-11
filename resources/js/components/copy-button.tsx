import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

export function CopyButton({
    value,
    label = 'Copy',
    className,
}: {
    value: string;
    label?: string;
    className?: string;
}) {
    const [copied, copy] = useClipboard();
    const done = copied === value;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className={cn('gap-1.5', className)}
            onClick={() => void copy(value)}
        >
            {done ? <Check aria-hidden /> : <Copy aria-hidden />}
            <span aria-live="polite">{done ? 'Copied' : label}</span>
        </Button>
    );
}
