import { Link } from '@inertiajs/react';
import { MessageCircle, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { gameJson, shortWhen, speciesEmoji } from '@/lib/game';
import { openChat } from '@/lib/pulse';
import { cn } from '@/lib/utils';
import {
    dock,
    messages as messagesPage,
    online as onlinePage,
} from '@/routes/play';
import type { MessageAnimal } from '@/types/game';

type Dock = {
    online: (MessageAnimal & { level: number; where: string | null })[];
    conversations: {
        animal: MessageAnimal;
        last: { body: string | null; mine: boolean; at: string };
        unread: number;
        blocked: boolean;
    }[];
};

function Face({ animal }: { animal: MessageAnimal }) {
    return (
        <span
            className="flex size-9 shrink-0 items-center justify-center rounded-full border-2 bg-background text-lg"
            style={{ borderColor: animal.colour ?? undefined }}
            aria-hidden
        >
            {speciesEmoji(animal.species)}
        </span>
    );
}

/**
 * The slide-out behind the corner buttons: who's online in the valley, and
 * your recent chats. Tap an animal to chat with it without leaving the page.
 */
export function DockPanel({
    tab,
    onTab,
}: {
    tab: 'online' | 'messages' | null;
    onTab: (tab: 'online' | 'messages' | null) => void;
}) {
    const [data, setData] = useState<Dock | null>(null);
    const open = tab !== null;

    useEffect(() => {
        if (!open) {
            return;
        }

        let stopped = false;
        let timer: ReturnType<typeof setTimeout>;

        const load = async () => {
            const result = await gameJson<Dock>(dock.url());

            if (stopped) {
                return;
            }

            if (result.ok) {
                setData(result.data);
            }

            timer = setTimeout(load, 20000);
        };

        void load();

        return () => {
            stopped = true;
            clearTimeout(timer);
        };
    }, [open]);

    const chat = (animal: MessageAnimal) => {
        openChat(animal.id, animal);
        onTab(null);
    };

    return (
        <Sheet open={open} onOpenChange={(next) => !next && onTab(null)}>
            <SheetContent
                side="right"
                className="flex w-[min(22rem,100vw)] flex-col gap-0 p-0"
            >
                <SheetHeader className="border-b pb-0">
                    <SheetTitle>
                        {tab === 'messages' ? 'Your chats' : "Who's online"}
                    </SheetTitle>
                    <SheetDescription className="sr-only">
                        Tap an animal to chat with it.
                    </SheetDescription>
                    <div className="-mb-px flex gap-1">
                        {(
                            [
                                [
                                    'online',
                                    'Online',
                                    Users,
                                    data?.online.length,
                                ],
                                [
                                    'messages',
                                    'Chats',
                                    MessageCircle,
                                    data?.conversations.reduce(
                                        (sum, c) => sum + c.unread,
                                        0,
                                    ),
                                ],
                            ] as const
                        ).map(([key, label, Icon, count]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => onTab(key)}
                                className={cn(
                                    'flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-semibold',
                                    tab === key
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Icon className="size-4" /> {label}
                                {count ? (
                                    <span className="rounded-full bg-muted px-1.5 text-xs">
                                        {count}
                                    </span>
                                ) : null}
                            </button>
                        ))}
                    </div>
                </SheetHeader>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {data === null ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            Looking…
                        </p>
                    ) : tab === 'online' ? (
                        data.online.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Nobody else is about just now.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {data.online.map((animal) => (
                                    <li key={animal.id}>
                                        <button
                                            type="button"
                                            onClick={() => chat(animal)}
                                            className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-accent"
                                        >
                                            <span className="relative">
                                                <Face animal={animal} />
                                                <span className="absolute -right-0.5 -bottom-0.5 size-3 rounded-full border-2 border-background bg-emerald-500" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-semibold">
                                                    {animal.name}
                                                    <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                                        level {animal.level}
                                                    </span>
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {animal.faction ? (
                                                        <span
                                                            style={{
                                                                color:
                                                                    animal.colour ??
                                                                    undefined,
                                                            }}
                                                        >
                                                            {animal.faction}
                                                        </span>
                                                    ) : (
                                                        'Lone wolf'
                                                    )}
                                                    {animal.where
                                                        ? ` · ${animal.where}`
                                                        : ''}
                                                </span>
                                            </span>
                                            <MessageCircle className="size-4 text-muted-foreground" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )
                    ) : data.conversations.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            No chats yet. Pick someone from Online to say hello.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {data.conversations.map((conversation) => (
                                <li key={conversation.animal.id}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            chat(conversation.animal)
                                        }
                                        className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-accent"
                                    >
                                        <Face animal={conversation.animal} />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-baseline gap-2">
                                                <span className="truncate text-sm font-semibold">
                                                    {conversation.animal.name}
                                                </span>
                                                <span className="ml-auto shrink-0 text-[11px] text-muted-foreground">
                                                    {shortWhen(
                                                        conversation.last.at,
                                                    )}
                                                </span>
                                            </span>
                                            <span
                                                className={cn(
                                                    'block truncate text-xs',
                                                    conversation.unread > 0
                                                        ? 'font-semibold text-foreground'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {conversation.last.mine
                                                    ? 'You: '
                                                    : ''}
                                                {conversation.last.body ??
                                                    'Hidden by our team'}
                                            </span>
                                        </span>
                                        {conversation.unread > 0 && (
                                            <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-bold text-white">
                                                {conversation.unread}
                                            </span>
                                        )}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <Link
                    href={tab === 'messages' ? messagesPage() : onlinePage()}
                    onClick={() => onTab(null)}
                    className="border-t px-4 py-3 text-center text-sm font-semibold text-primary hover:bg-accent"
                >
                    {tab === 'messages'
                        ? 'All messages'
                        : "The whole who's online page"}{' '}
                    →
                </Link>
            </SheetContent>
        </Sheet>
    );
}
