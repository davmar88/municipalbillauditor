import { Link } from '@inertiajs/react';
import { Maximize2, Minus, Send, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ProfileLink } from '@/components/game/profile-link';
import { gameJson, shortWhen, speciesEmoji } from '@/lib/game';
import { closeChat, dropHead, keepHead, usePulse } from '@/lib/pulse';
import { cn } from '@/lib/utils';
import { talk } from '@/routes/play';
import { show as conversation } from '@/routes/play/messages';
import { say } from '@/routes/play/talk';
import type { MessageAnimal } from '@/types/game';

type Talk = {
    animal: MessageAnimal;
    messages: {
        id: number;
        body: string | null;
        at: string;
        mine: boolean;
        read: boolean;
    }[];
    cantWrite: string | null;
    maxLength: number;
};

/**
 * A chat window over whatever screen you're on, opened from any animal's
 * name. It checks for replies every few seconds while it's open.
 */
export function FloatingChat() {
    const chatWith = usePulse((state) => state.chatWith);
    const tick = usePulse((state) => state.chatTick);
    const [talking, setTalking] = useState<Talk | null>(null);
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const list = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        if (chatWith === null) {
            return;
        }

        let stopped = false;
        let timer: ReturnType<typeof setTimeout>;

        const load = async () => {
            const result = await gameJson<Talk>(talk.url(chatWith));

            if (stopped) {
                return;
            }

            if (result.ok) {
                setTalking(result.data);
                keepHead(result.data.animal);
            } else if (result.status === 404) {
                closeChat();

                return;
            }

            timer = setTimeout(load, document.hidden ? 20000 : 5000);
        };

        void load();

        return () => {
            stopped = true;
            clearTimeout(timer);
        };
    }, [chatWith, tick]);

    // A different animal: start clean.
    useEffect(() => {
        setTalking(null);
        setError(null);
        setBody('');
        input.current?.focus();
    }, [chatWith]);

    const count = talking?.messages.length ?? 0;

    useEffect(() => {
        list.current?.scrollTo({ top: list.current.scrollHeight });
    }, [count, talking?.animal.id]);

    if (chatWith === null) {
        return null;
    }

    const send = async () => {
        const text = body.trim();

        if (text === '' || sending) {
            return;
        }

        setSending(true);
        setError(null);
        const result = await gameJson<Talk>(say.url(chatWith), 'POST', {
            body: text,
        });
        setSending(false);

        if (result.ok) {
            setTalking(result.data);
            setBody('');
            input.current?.focus();
        } else {
            setError(result.error);
        }
    };

    const animal = talking?.animal;

    return (
        <section
            aria-label={animal ? `Chat with ${animal.name}` : 'Chat'}
            className="fixed right-3 bottom-[calc(4.25rem+env(safe-area-inset-bottom))] z-50 flex h-[min(30rem,65svh)] w-[min(22rem,calc(100vw-1.5rem))] animate-in flex-col overflow-hidden rounded-2xl border bg-card shadow-2xl fade-in slide-in-from-bottom-4 md:right-[5.5rem] md:bottom-5"
        >
            <header className="flex items-center gap-2 border-b px-3 py-2.5">
                <span
                    className="flex size-9 shrink-0 items-center justify-center rounded-full border-2 bg-background text-lg"
                    style={{ borderColor: animal?.colour ?? undefined }}
                    aria-hidden
                >
                    {animal ? speciesEmoji(animal.species) : '…'}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate font-bold">
                        {animal ? (
                            // The profile has its own Message button, so the window steps aside.
                            <ProfileLink
                                animal={animal}
                                onClick={() => closeChat()}
                            />
                        ) : (
                            'Opening…'
                        )}
                    </p>
                    {animal?.faction && (
                        <p
                            className="truncate text-xs"
                            style={{ color: animal.colour ?? undefined }}
                        >
                            {animal.faction}
                        </p>
                    )}
                </div>
                {animal && (
                    <Link
                        href={conversation(animal.id)}
                        onClick={() => closeChat()}
                        className="flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Open the whole conversation"
                        title="Open the whole conversation"
                    >
                        <Maximize2 className="size-4" />
                    </Link>
                )}
                <button
                    type="button"
                    onClick={() => closeChat()}
                    className="flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Fold the chat into the corner"
                    title="Fold it into the corner"
                >
                    <Minus className="size-4" />
                </button>
                <button
                    type="button"
                    onClick={() => dropHead(chatWith)}
                    className="flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Close the chat"
                    title="Close the chat"
                >
                    <X className="size-4" />
                </button>
            </header>

            <div
                ref={list}
                className="flex flex-1 flex-col gap-1.5 overflow-y-auto px-3 py-3"
                aria-live="polite"
            >
                {talking && talking.messages.length === 0 && (
                    <p className="m-auto max-w-[16rem] text-center text-sm text-muted-foreground">
                        Say hello to {talking.animal.name}. They'll see it in
                        their messages, wherever they are in the valley.
                    </p>
                )}
                {talking?.messages.map((message) => (
                    <div
                        key={message.id}
                        className={cn(
                            'max-w-[85%] rounded-2xl px-3 py-1.5 text-sm',
                            message.mine
                                ? 'self-end rounded-br-md bg-primary text-primary-foreground'
                                : 'self-start rounded-bl-md bg-muted',
                        )}
                    >
                        <p className="break-words whitespace-pre-wrap">
                            {message.body ?? (
                                <span className="italic opacity-70">
                                    Hidden by our team
                                </span>
                            )}
                        </p>
                        <p
                            className={cn(
                                'mt-0.5 text-[10px]',
                                message.mine
                                    ? 'text-primary-foreground/70'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {shortWhen(message.at)}
                            {message.mine && message.read && ' · read'}
                        </p>
                    </div>
                ))}
            </div>

            <footer className="border-t p-2">
                {talking?.cantWrite ? (
                    <p className="px-1 py-2 text-sm text-muted-foreground">
                        {talking.cantWrite}
                    </p>
                ) : (
                    <form
                        className="flex items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void send();
                        }}
                    >
                        <textarea
                            ref={input}
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    void send();
                                }
                            }}
                            maxLength={talking?.maxLength ?? 1000}
                            rows={1}
                            placeholder={
                                animal ? `Write to ${animal.name}` : 'Write…'
                            }
                            aria-label="Your message"
                            className="max-h-28 min-h-10 flex-1 resize-none rounded-xl border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <button
                            type="submit"
                            disabled={sending || body.trim() === ''}
                            className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground disabled:opacity-50"
                            aria-label="Send"
                        >
                            <Send className="size-4" />
                        </button>
                    </form>
                )}
                {error && (
                    <p className="px-1 pt-1 text-xs text-orange-300">{error}</p>
                )}
            </footer>
        </section>
    );
}
