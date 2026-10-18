import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    Flag,
    Languages,
    MoreHorizontal,
    Send,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { OrchardBadge } from '@/components/game/orchard-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import {
    act,
    capitalise,
    fetchTranslation,
    shortWhen,
    speciesEmoji,
} from '@/lib/game';
import { cn } from '@/lib/utils';
import { messages as inbox } from '@/routes/play';
import {
    block,
    report,
    store,
    translate,
    unblock,
} from '@/routes/play/messages';
import type { MessageAnimal } from '@/types/game';

type Message = {
    id: number;
    body: string | null;
    translation: string | null;
    at: string;
    mine: boolean;
    read: boolean;
    reported: boolean;
};

type Props = {
    animal: MessageAnimal;
    messages: Message[];
    blocked: boolean;
    cantWrite: string | null;
    canTranslate: boolean;
    language: string;
    maxLength: number;
    reasons: { value: string; label: string }[];
};

/** A private conversation with one other animal. */
export default function ConversationPage({
    animal,
    messages,
    blocked,
    cantWrite,
    canTranslate,
    language,
    maxLength,
    reasons,
}: Props) {
    const form = useForm({ body: '' });
    const [translations, setTranslations] = useState<Record<number, string>>(
        {},
    );
    const [reporting, setReporting] = useState<Message | null>(null);
    const end = useRef<HTMLDivElement>(null);
    const lastSeen = [...messages].reverse().find((m) => m.mine)?.id;

    // Their replies arrive every 10 seconds while the page is open.
    usePoll(10000, { only: ['messages', 'blocked', 'cantWrite'] });

    // Keep the newest message in view as the conversation grows.
    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);

    async function translateMessage(message: Message) {
        const result = await fetchTranslation(translate.url(message.id));

        if ('error' in result) {
            toast.error(result.error);

            return;
        }

        setTranslations((current) => ({
            ...current,
            [message.id]: result.translation,
        }));
    }

    return (
        <>
            <Head title={`Messages with ${animal.name}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <header className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={inbox()} aria-label="Back to messages">
                            <ArrowLeft className="size-5" />
                        </Link>
                    </Button>
                    <span className="text-3xl" aria-hidden>
                        {speciesEmoji(animal.species)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1
                            className="truncate text-xl font-extrabold tracking-tight"
                            style={
                                animal.colour
                                    ? { color: animal.colour }
                                    : undefined
                            }
                        >
                            {animal.name}
                            <OrchardBadge member={animal.orchard} />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {capitalise(animal.species)}
                            {animal.faction
                                ? ` · ${animal.faction}`
                                : ' · Lone wolf'}
                        </p>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={`More for ${animal.name}`}
                            >
                                <MoreHorizontal className="size-5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {blocked ? (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        act(
                                            unblock.url(animal.id),
                                            {},
                                            'delete',
                                        )
                                    }
                                >
                                    <Ban className="size-4" /> Unblock{' '}
                                    {animal.name}
                                </DropdownMenuItem>
                            ) : (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        confirm(
                                            `Block ${animal.name}? Neither of you will be able to write to the other.`,
                                        ) && act(block.url(animal.id))
                                    }
                                >
                                    <Ban className="size-4" /> Block{' '}
                                    {animal.name}
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </header>

                <ol className="flex min-h-64 flex-col gap-3 rounded-xl border bg-card p-4">
                    {messages.length === 0 && (
                        <li className="text-sm text-muted-foreground">
                            Nothing said yet. Say hello to {animal.name}.
                        </li>
                    )}
                    {messages.map((message) => {
                        const translated =
                            translations[message.id] ?? message.translation;

                        return (
                            <li
                                key={message.id}
                                className={cn(
                                    'flex flex-col gap-1',
                                    message.mine && 'items-end',
                                )}
                            >
                                <span className="text-xs text-muted-foreground">
                                    {message.mine ? 'You' : animal.name} ·{' '}
                                    {shortWhen(message.at)}
                                </span>
                                {message.body === null ? (
                                    <p className="max-w-[85%] rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground italic">
                                        Removed by our team.
                                    </p>
                                ) : (
                                    <p
                                        className={cn(
                                            'max-w-[85%] rounded-lg px-3 py-2 text-[15px] break-words whitespace-pre-wrap',
                                            message.mine
                                                ? 'bg-primary/20'
                                                : 'bg-muted',
                                        )}
                                    >
                                        {message.body}
                                    </p>
                                )}
                                {translated && (
                                    <p className="max-w-[85%] text-sm text-cyan-200">
                                        {language}: {translated}
                                    </p>
                                )}
                                {!message.mine && message.body !== null && (
                                    <span className="flex gap-3 text-xs">
                                        {!translated && canTranslate && (
                                            <button
                                                type="button"
                                                className="flex items-center gap-1 text-primary hover:underline"
                                                onClick={() =>
                                                    void translateMessage(
                                                        message,
                                                    )
                                                }
                                            >
                                                <Languages className="size-3.5" />{' '}
                                                Translate into {language}
                                            </button>
                                        )}
                                        {message.reported ? (
                                            <span className="text-muted-foreground">
                                                Reported
                                            </span>
                                        ) : (
                                            <button
                                                type="button"
                                                className="flex items-center gap-1 text-muted-foreground hover:text-foreground hover:underline"
                                                onClick={() =>
                                                    setReporting(message)
                                                }
                                            >
                                                <Flag className="size-3.5" />{' '}
                                                Report
                                            </button>
                                        )}
                                    </span>
                                )}
                                {message.id === lastSeen && message.read && (
                                    <span className="text-xs text-muted-foreground">
                                        Seen
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ol>

                {cantWrite ? (
                    <p className="rounded-md border px-3 py-2 text-sm text-muted-foreground">
                        {cantWrite}
                    </p>
                ) : (
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(store.url(animal.id), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset('body');
                                    router.reload({ only: ['messages'] });
                                },
                            });
                        }}
                    >
                        <Textarea
                            value={form.data.body}
                            maxLength={maxLength}
                            rows={3}
                            placeholder={`Write to ${animal.name}`}
                            aria-label={`Message to ${animal.name}`}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    e.currentTarget.form?.requestSubmit();
                                }
                            }}
                        />
                        <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                            <span>
                                {form.data.body.length}/{maxLength} · Only{' '}
                                {animal.name} reads this. Be decent: no real
                                politics, slurs or contact details.
                            </span>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={
                                    form.processing || !form.data.body.trim()
                                }
                            >
                                <Send className="size-4" /> Send
                            </Button>
                        </div>
                    </form>
                )}
                <div ref={end} />
            </div>

            <ReportDialog
                message={reporting}
                animal={animal}
                reasons={reasons}
                onClose={() => setReporting(null)}
            />
        </>
    );
}

function ReportDialog({
    message,
    animal,
    reasons,
    onClose,
}: {
    message: Message | null;
    animal: MessageAnimal;
    reasons: Props['reasons'];
    onClose: () => void;
}) {
    const [reason, setReason] = useState(reasons[0]?.value ?? 'other');
    const [note, setNote] = useState('');
    const [alsoBlock, setAlsoBlock] = useState(true);

    return (
        <Dialog
            open={message !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Report this message</DialogTitle>
                    <DialogDescription>
                        Our team reads the message and the few before it. They
                        can remove it and stop {animal.name} writing for a
                        while.
                    </DialogDescription>
                </DialogHeader>
                {message?.body && (
                    <blockquote className="rounded-md bg-muted px-3 py-2 text-sm break-words whitespace-pre-wrap">
                        {message.body}
                    </blockquote>
                )}
                <label className="grid gap-1 text-sm">
                    What's wrong with it
                    <NativeSelect
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    >
                        {reasons.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                </label>
                <label className="grid gap-1 text-sm">
                    Anything our team should know (optional)
                    <Textarea
                        value={note}
                        maxLength={300}
                        rows={2}
                        onChange={(e) => setNote(e.target.value)}
                    />
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="size-4 accent-primary"
                        checked={alsoBlock}
                        onChange={(e) => setAlsoBlock(e.target.checked)}
                    />
                    Also block {animal.name}
                </label>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button
                        onClick={() => {
                            if (message) {
                                act(report.url(message.id), {
                                    reason,
                                    note,
                                    block: alsoBlock,
                                });
                            }

                            setNote('');
                            onClose();
                        }}
                    >
                        <Flag className="size-4" /> Send report
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
