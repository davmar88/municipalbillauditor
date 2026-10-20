import { router, usePage } from '@inertiajs/react';
import { Bell, MessageCircle, Users, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DockPanel } from '@/components/game/dock-panel';
import { FloatingChat } from '@/components/game/floating-chat';
import { Button } from '@/components/ui/button';
import { gameJson, speciesEmoji } from '@/lib/game';
import { cn } from '@/lib/utils';
import type { Popup } from '@/lib/pulse';
import {
    chatOpenWith,
    closeChat,
    dismissPopup,
    dropHead,
    messageFrom,
    openChat,
    pushPopup,
    setUnread,
    usePulse,
} from '@/lib/pulse';
import { pulse } from '@/routes/play';
import type { MessageAnimal } from '@/types/game';

type Pulse = {
    unreadMessages: number;
    unreadNotices: number;
    latestMessage: number;
    latestNotice: number;
    messages: { id: number; from: MessageAnimal; body: string }[];
    notices: { id: number; kind: string; text: string; url: string | null }[];
};

/**
 * Keeps every game screen in touch: checks for new messages and news,
 * pops them up in the corner, keeps the badges right, and holds the chat
 * window you open from any animal's name.
 */
export function PlayerPulse() {
    const { player, unreadMessages, unreadNotices } = usePage().props;
    const popups = usePulse((state) => state.popups);
    const playerId = player?.id ?? null;

    // Each page load brings fresh counts.
    useEffect(() => {
        setUnread(unreadMessages ?? 0, unreadNotices ?? 0);
    }, [unreadMessages, unreadNotices]);

    useEffect(() => {
        if (playerId === null) {
            return;
        }

        let stopped = false;
        let timer: ReturnType<typeof setTimeout>;
        // The first check learns where things stand; later ones bring what's new.
        let seen: { messages: number; notices: number } | null = null;

        const check = async () => {
            const result = await gameJson<Pulse>(
                pulse.url(seen ? { query: { ...seen } } : undefined),
            );

            if (stopped) {
                return;
            }

            if (result.ok) {
                const data = result.data;
                setUnread(data.unreadMessages, data.unreadNotices);

                if (seen) {
                    data.messages.forEach((message) => {
                        messageFrom(message.from);

                        if (chatOpenWith() !== message.from.id) {
                            pushPopup({
                                key: `message-${message.id}`,
                                kind: 'message',
                                text: message.body,
                                from: message.from,
                            });
                        }
                    });
                    data.notices.forEach((notice) =>
                        pushPopup({
                            key: `notice-${notice.id}`,
                            kind: 'notice',
                            text: notice.text,
                            url: notice.url,
                        }),
                    );
                }

                seen = {
                    messages: Math.max(seen?.messages ?? 0, data.latestMessage),
                    notices: Math.max(seen?.notices ?? 0, data.latestNotice),
                };
            }

            timer = setTimeout(check, document.hidden ? 60000 : 15000);
        };

        void check();

        return () => {
            stopped = true;
            clearTimeout(timer);
        };
    }, [playerId]);

    if (playerId === null) {
        return null;
    }

    return (
        <>
            <div className="pointer-events-none fixed top-[4.5rem] right-3 z-50 flex w-[min(21rem,calc(100vw-1.5rem))] flex-col gap-2 md:right-5">
                {popups.length > 1 && (
                    <button
                        type="button"
                        onClick={() =>
                            popups.forEach((p) => dismissPopup(p.key))
                        }
                        className="pointer-events-auto self-end rounded-full border bg-card/95 px-3 py-1 text-xs font-semibold shadow hover:bg-accent"
                    >
                        Clear all
                    </button>
                )}
                {popups.map((popup) => (
                    <PopupCard key={popup.key} popup={popup} />
                ))}
            </div>
            <FloatingChat />
            <ChatHeads />
        </>
    );
}

/**
 * The animals you've been chatting with, as round heads in the bottom-right
 * corner: tap one to open its chat again, wherever you are in the game.
 */
function ChatHeads() {
    const heads = usePulse((state) => state.heads);
    const unread = usePulse((state) => state.unreadHeads);
    const chatWith = usePulse((state) => state.chatWith);
    const unreadMessages = usePulse((state) => state.unreadMessages);
    const [panel, setPanel] = useState<'online' | 'messages' | null>(null);

    return (
        <>
            <DockPanel tab={panel} onTab={setPanel} />
            <nav
                aria-label="Your chats"
                className={cn(
                    'fixed right-3 bottom-[calc(4.25rem+env(safe-area-inset-bottom))] z-50 flex-col-reverse gap-2 md:right-5 md:bottom-5',
                    // On a phone the open chat covers the corner, so the heads wait behind it.
                    chatWith === null ? 'flex' : 'hidden md:flex',
                )}
            >
                <button
                    type="button"
                    onClick={() => setPanel('online')}
                    className="flex size-12 items-center justify-center rounded-full border-2 border-emerald-500 bg-card text-emerald-500 shadow-lg transition hover:scale-105"
                    aria-label="Who's online"
                    title="Who's online"
                >
                    <Users className="size-5" />
                </button>
                <div className="relative">
                    <button
                        type="button"
                        onClick={() => setPanel('messages')}
                        className="flex size-12 items-center justify-center rounded-full border-2 border-primary bg-card text-primary shadow-lg transition hover:scale-105"
                        aria-label="Your chats"
                        title="Your chats"
                    >
                        <MessageCircle className="size-5" />
                    </button>
                    {(unreadMessages ?? 0) > 0 && (
                        <span className="pointer-events-none absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-bold text-white">
                            {unreadMessages}
                        </span>
                    )}
                </div>
                {heads.length > 0 && (
                    <span className="mx-auto h-px w-8 bg-border" aria-hidden />
                )}
                {heads.map((head) => (
                    <div key={head.id} className="group relative">
                        <button
                            type="button"
                            onClick={() =>
                                chatWith === head.id
                                    ? closeChat()
                                    : openChat(head.id, head)
                            }
                            className={cn(
                                'flex size-12 items-center justify-center rounded-full border-2 bg-card text-2xl shadow-lg transition hover:scale-105',
                                chatWith === head.id && 'ring-2 ring-primary',
                            )}
                            style={{ borderColor: head.colour ?? undefined }}
                            aria-label={`Chat with ${head.name}`}
                            title={head.name}
                        >
                            <span aria-hidden>
                                {speciesEmoji(head.species)}
                            </span>
                        </button>
                        {unread.includes(head.id) && (
                            <span
                                className="pointer-events-none absolute -top-0.5 -right-0.5 size-3.5 rounded-full border-2 border-card bg-red-500"
                                aria-label="New message"
                            />
                        )}
                        <button
                            type="button"
                            onClick={() => dropHead(head.id)}
                            className="absolute -top-1 -left-1 hidden size-5 items-center justify-center rounded-full border bg-card text-muted-foreground shadow group-hover:flex hover:text-foreground"
                            aria-label={`Close the chat with ${head.name}`}
                        >
                            <X className="size-3" />
                        </button>
                    </div>
                ))}
            </nav>
        </>
    );
}

function PopupCard({ popup }: { popup: Popup }) {
    useEffect(() => {
        const timer = setTimeout(() => dismissPopup(popup.key), 12000);

        return () => clearTimeout(timer);
    }, [popup.key]);

    return (
        <div
            role="status"
            className="pointer-events-auto flex animate-in items-start gap-3 rounded-xl border bg-card/95 p-3 shadow-xl backdrop-blur fade-in slide-in-from-right-4"
        >
            <span
                className="flex size-9 shrink-0 items-center justify-center rounded-full border-2 bg-background text-lg"
                style={{ borderColor: popup.from?.colour ?? undefined }}
                aria-hidden
            >
                {popup.from ? (
                    speciesEmoji(popup.from.species)
                ) : (
                    <Bell className="size-4 text-primary" />
                )}
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold">
                    {popup.from ? popup.from.name : 'News'}
                </p>
                <p className="line-clamp-3 text-sm break-words text-muted-foreground">
                    {popup.text}
                </p>
                {popup.from ? (
                    <Button
                        size="sm"
                        className="mt-2"
                        onClick={() => openChat(popup.from!.id, popup.from)}
                    >
                        Reply
                    </Button>
                ) : (
                    popup.url && (
                        <Button
                            size="sm"
                            variant="secondary"
                            className="mt-2"
                            onClick={() => {
                                dismissPopup(popup.key);
                                router.visit(popup.url!);
                            }}
                        >
                            Open
                        </Button>
                    )
                )}
            </div>
            <button
                type="button"
                onClick={() => dismissPopup(popup.key)}
                className="-mt-1 -mr-1 flex size-7 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
                aria-label="Dismiss"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
