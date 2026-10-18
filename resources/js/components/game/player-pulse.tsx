import { router, usePage } from '@inertiajs/react';
import { Bell, X } from 'lucide-react';
import { useEffect } from 'react';
import { FloatingChat } from '@/components/game/floating-chat';
import { Button } from '@/components/ui/button';
import { gameJson, speciesEmoji } from '@/lib/game';
import type { Popup } from '@/lib/pulse';
import {
    chatOpenWith,
    dismissPopup,
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
                {popups.map((popup) => (
                    <PopupCard key={popup.key} popup={popup} />
                ))}
            </div>
            <FloatingChat />
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
                        onClick={() => openChat(popup.from!.id)}
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
