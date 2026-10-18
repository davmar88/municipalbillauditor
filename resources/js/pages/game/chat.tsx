import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Languages, Send } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { OrchardBadge } from '@/components/game/orchard-badge';
import { ProfileLink } from '@/components/game/profile-link';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { capitalise, clockTime, fetchTranslation } from '@/lib/game';
import { cn } from '@/lib/utils';
import { chat } from '@/routes/play';
import { store, translate } from '@/routes/play/chat';

type Message = {
    id: number;
    body: string;
    translation: string | null;
    at: string;
    mine: boolean;
    from: {
        id: number;
        name: string;
        species: string;
        faction: string | null;
        colour: string | null;
        orchard: boolean;
    };
};

type Props = {
    channels: { key: string; label: string; colour: string | null }[];
    channel: string;
    messages: Message[];
    canTranslate: boolean;
    language: string;
    maxLength: number;
    silenced: string | null;
};

export default function ChatPage({
    channels,
    channel,
    messages,
    canTranslate,
    language,
    maxLength,
    silenced,
}: Props) {
    const form = useForm({ channel, body: '' });
    const [translations, setTranslations] = useState<Record<number, string>>(
        {},
    );

    // New messages arrive every 10 seconds while the page is open.
    usePoll(10000, { only: ['messages'] });

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
            <Head title="Chat" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <h1 className="text-2xl font-extrabold tracking-tight">Chat</h1>

                <nav
                    className="flex gap-2 overflow-x-auto pb-1"
                    aria-label="Channels"
                >
                    {channels.map((c) => (
                        <Link
                            key={c.key}
                            href={chat({ query: { channel: c.key } })}
                            preserveScroll
                            className={cn(
                                'shrink-0 rounded-full border px-3 py-1.5 text-sm font-semibold no-underline',
                                c.key === channel
                                    ? 'border-primary bg-primary/15 text-foreground'
                                    : 'text-muted-foreground',
                            )}
                            style={
                                c.colour
                                    ? {
                                          borderColor:
                                              c.key === channel
                                                  ? undefined
                                                  : c.colour,
                                      }
                                    : undefined
                            }
                        >
                            {c.label}
                        </Link>
                    ))}
                </nav>

                <ol className="flex min-h-64 flex-col gap-3 rounded-xl border bg-card p-4">
                    {messages.length === 0 && (
                        <li className="text-sm text-muted-foreground">
                            No messages yet. Say something.
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
                                    {message.mine ? (
                                        <span className="font-semibold">
                                            {message.from.name}
                                        </span>
                                    ) : (
                                        <ProfileLink
                                            animal={message.from}
                                            className="font-semibold"
                                            style={
                                                message.from.colour
                                                    ? {
                                                          color: message.from
                                                              .colour,
                                                      }
                                                    : undefined
                                            }
                                        />
                                    )}
                                    <OrchardBadge
                                        member={message.from.orchard}
                                    />{' '}
                                    the {capitalise(message.from.species)}
                                    {message.from.faction &&
                                        ` · ${message.from.faction}`}{' '}
                                    · {clockTime(message.at)}
                                </span>
                                <p
                                    className={cn(
                                        'max-w-[85%] rounded-lg px-3 py-2 text-[15px] whitespace-pre-wrap',
                                        message.mine
                                            ? 'bg-primary/20'
                                            : 'bg-muted',
                                    )}
                                >
                                    {message.body}
                                </p>
                                {translated ? (
                                    <p className="max-w-[85%] text-sm text-cyan-200">
                                        {language}: {translated}
                                    </p>
                                ) : (
                                    !message.mine &&
                                    canTranslate && (
                                        <button
                                            type="button"
                                            className="flex items-center gap-1 text-xs text-primary hover:underline"
                                            onClick={() =>
                                                void translateMessage(message)
                                            }
                                        >
                                            <Languages className="size-3.5" />{' '}
                                            Translate into {language}
                                        </button>
                                    )
                                )}
                            </li>
                        );
                    })}
                </ol>

                {silenced && (
                    <p className="rounded-md border border-orange-500/40 bg-orange-500/10 px-3 py-2 text-sm text-orange-200">
                        {silenced}
                    </p>
                )}

                <form
                    className="flex flex-col gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(store.url(), {
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
                        rows={2}
                        placeholder="Write to the channel"
                        onChange={(e) => form.setData('body', e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                e.currentTarget.form?.requestSubmit();
                            }
                        }}
                    />
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <span>
                            {form.data.body.length}/{maxLength} · Be decent: no
                            real politics, slurs or real people.
                        </span>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={form.processing || !form.data.body.trim()}
                        >
                            <Send className="size-4" /> Send
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
