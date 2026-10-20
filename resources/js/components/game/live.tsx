import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import { messages as messagesPage } from '@/routes/play';
import { chatOpenWith, messageFrom, openChat, pushPopup } from '@/lib/pulse';
import type { MessageAnimal } from '@/types/game';

/**
 * Live updates over the websocket (Laravel Reverb). Nothing here runs
 * unless the build knows the Reverb key, so a server without one keeps
 * working as before: pages still fetch what they need on their own.
 */
export const liveEnabled = Boolean(import.meta.env.VITE_REVERB_APP_KEY);

/**
 * Run a reload at most once in a while, however many events arrive: a
 * busy valley sends a burst, and one fetch catches them all.
 */
function useThrottled(run: () => void, ms: number): () => void {
    const last = useRef(0);
    const timer = useRef<number | null>(null);
    const latest = useRef(run);

    useEffect(() => {
        latest.current = run;
    }, [run]);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    return () => {
        if (timer.current !== null) {
            return;
        }
        const wait = Math.max(0, last.current + ms - Date.now());
        timer.current = window.setTimeout(() => {
            timer.current = null;
            last.current = Date.now();
            latest.current();
        }, wait);
    };
}

type Notice = { kind: string; text: string; url: string | null };
type Message = {
    id: number;
    from: number;
    name: string;
    preview: string;
    sender?: MessageAnimal;
};

/** Your bell and your letters: each notice and private message pops up as it arrives. */
export function LiveBell({ playerId }: { playerId: number }) {
    const page = usePage();
    const refresh = useThrottled(
        () =>
            router.reload({
                only: [
                    'player',
                    'unreadNotices',
                    'unreadMessages',
                    'conversations',
                    'messages',
                ],
            }),
        1000,
    );

    useEcho<Notice>(
        `player.${playerId}`,
        '.notice',
        (notice) => {
            toast(notice.text, {
                action: notice.url
                    ? {
                          label: 'Open',
                          onClick: () => router.visit(notice.url as string),
                      }
                    : undefined,
            });
            refresh();
        },
        [],
    );

    useEcho<Message>(
        `player.${playerId}`,
        '.message',
        (message) => {
            const props = page.props as { animal?: { id: number } };
            const reading =
                page.component === 'game/conversation' &&
                props.animal?.id === message.from;

            if (message.sender) {
                messageFrom(message.sender);
            }

            if (!reading && chatOpenWith() !== message.from) {
                if (message.sender) {
                    pushPopup({
                        key: `message-${message.id}`,
                        kind: 'message',
                        text: message.preview,
                        from: message.sender,
                    });
                } else {
                    toast(`✉️ ${message.name}: ${message.preview}`, {
                        action: {
                            label: 'Reply',
                            onClick: () => openChat(message.from),
                        },
                        cancel: {
                            label: 'All messages',
                            onClick: () => router.visit(messagesPage.url()),
                        },
                    });
                }
            }
            refresh();
        },
        [page.component, page.props],
    );

    return null;
}

/** The map: whenever anything changes in the valley, fetch what's new. */
export function LiveMap({
    valleyId,
    only,
}: {
    valleyId: number;
    only: string[];
}) {
    const refresh = useThrottled(() => router.reload({ only }), 1500);

    useEcho(`valley.${valleyId}`, '.map', () => refresh(), []);

    return null;
}

/** A chat channel: new lines show the moment they're written. */
export function LiveChat({
    valleyId,
    channel,
}: {
    valleyId: number;
    channel: string;
}) {
    const refresh = useThrottled(
        () => router.reload({ only: ['messages'] }),
        500,
    );

    useEcho<{ channel: string | null }>(
        `valley.${valleyId}`,
        '.chat',
        (event) => {
            if (event.channel === channel) {
                refresh();
            }
        },
        [channel],
    );

    return null;
}
