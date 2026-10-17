import { Head } from '@inertiajs/react';
import { ItemPreview } from '@/components/game/item-preview';
import { Button } from '@/components/ui/button';
import { act } from '@/lib/game';
import { approve, reject } from '@/routes/staff/designs';
import type { DesignCard } from '@/types';

type Props = {
    designs: (DesignCard & { maker: string; at: string })[];
};

/** A new maker's first three designs, checked by a person before sale. */
export default function Designs({ designs }: Props) {
    return (
        <>
            <Head title="Designs to check" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <h1 className="text-2xl font-extrabold tracking-tight">
                    Designs to check
                </h1>
                <p className="text-sm text-muted-foreground">
                    Reject anything offensive, and anything showing real brands,
                    real people or real political symbols.
                </p>
                {designs.length === 0 ? (
                    <p className="text-muted-foreground">Nothing waiting.</p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {designs.map((design) => (
                            <li
                                key={design.id}
                                className="flex items-center gap-4 rounded-xl border bg-card p-3"
                            >
                                <ItemPreview design={design} />
                                <div className="flex-1">
                                    <p className="font-semibold">
                                        {design.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {design.templateLabel} by {design.maker}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    onClick={() => act(approve.url(design.id))}
                                >
                                    Approve
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => act(reject.url(design.id))}
                                >
                                    Reject
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
