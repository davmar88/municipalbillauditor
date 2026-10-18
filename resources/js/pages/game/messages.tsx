import { Head, Link, router, usePoll } from '@inertiajs/react';
import { PenLine, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { OrchardBadge } from '@/components/game/orchard-badge';
import { Input } from '@/components/ui/input';
import { capitalise, shortWhen, speciesEmoji } from '@/lib/game';
import { cn } from '@/lib/utils';
import { messages as inbox } from '@/routes/play';
import { show } from '@/routes/play/messages';
import type { MessageAnimal } from '@/types/game';

type Props = {
    conversations: {
        animal: MessageAnimal;
        last: { body: string | null; mine: boolean; at: string };
        unread: number;
        blocked: boolean;
    }[];
    search: string;
    found: MessageAnimal[];
};

/** Your private conversations, and a search to write to anyone. */
export default function MessagesPage({ conversations, search, found }: Props) {
    const [query, setQuery] = useState(search);
    const wanted = query.trim();
    // Names need two letters before the search runs.
    const target = wanted.length >= 2 ? wanted : '';

    // New messages show up while the inbox is open.
    usePoll(15000, { only: ['conversations'] });

    // Look the name up once typing pauses.
    useEffect(() => {
        if (target === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(inbox.url(), target ? { q: target } : {}, {
                only: ['found', 'search'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);

        return () => clearTimeout(timer);
    }, [target, search]);

    return (
        <>
            <Head title="Messages" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-extrabold tracking-tight">
                        Messages
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Write privately to any animal in the valley.
                    </p>
                </header>

                <section className="flex flex-col gap-2 rounded-xl border bg-card p-4">
                    <label htmlFor="find-animal" className="font-semibold">
                        Write to someone
                    </label>
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            id="find-animal"
                            type="search"
                            value={query}
                            autoComplete="off"
                            placeholder="Their animal's name"
                            className="pl-9"
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </div>
                    {target !== '' &&
                        target === search &&
                        (found.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No animal goes by that name.
                            </p>
                        ) : (
                            <ul className="flex flex-col divide-y">
                                {found.map((animal) => (
                                    <li key={animal.id}>
                                        <Link
                                            href={show(animal.id)}
                                            className="flex min-h-12 items-center gap-3 py-2 no-underline"
                                        >
                                            <AnimalLine animal={animal} />
                                            <PenLine
                                                className="ml-auto size-4 shrink-0 text-primary"
                                                aria-hidden
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ))}
                </section>

                {conversations.length === 0 ? (
                    <p className="text-muted-foreground">
                        No messages yet. Find an animal above, or tap a name in
                        chat, the market or your faction to write to them.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {conversations.map((conversation) => (
                            <li key={conversation.animal.id}>
                                <Link
                                    href={show(conversation.animal.id)}
                                    className="flex items-start gap-3 px-4 py-3 no-underline hover:bg-muted/40"
                                >
                                    <span className="text-2xl" aria-hidden>
                                        {speciesEmoji(
                                            conversation.animal.species,
                                        )}
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span className="flex items-baseline gap-2">
                                            <span
                                                className="truncate font-semibold"
                                                style={
                                                    conversation.animal.colour
                                                        ? {
                                                              color: conversation
                                                                  .animal
                                                                  .colour,
                                                          }
                                                        : undefined
                                                }
                                            >
                                                {conversation.animal.name}
                                            </span>
                                            {conversation.blocked && (
                                                <span className="text-xs text-muted-foreground">
                                                    Blocked
                                                </span>
                                            )}
                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                {shortWhen(
                                                    conversation.last.at,
                                                )}
                                            </span>
                                        </span>
                                        <span
                                            className={cn(
                                                'truncate text-sm',
                                                conversation.unread > 0
                                                    ? 'font-semibold text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {conversation.last.mine && 'You: '}
                                            {conversation.last.body ?? (
                                                <em>Removed by our team</em>
                                            )}
                                        </span>
                                    </span>
                                    {conversation.unread > 0 && (
                                        <span className="mt-1 min-w-6 rounded-full bg-primary px-1.5 text-center text-xs leading-6 font-bold text-primary-foreground">
                                            {conversation.unread}
                                            <span className="sr-only">
                                                {' '}
                                                unread
                                            </span>
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function AnimalLine({ animal }: { animal: MessageAnimal }) {
    return (
        <>
            <span className="text-2xl" aria-hidden>
                {speciesEmoji(animal.species)}
            </span>
            <span className="flex min-w-0 flex-col">
                <span
                    className="truncate font-semibold"
                    style={animal.colour ? { color: animal.colour } : undefined}
                >
                    {animal.name}
                    <OrchardBadge member={animal.orchard} />
                </span>
                <span className="text-xs text-muted-foreground">
                    {capitalise(animal.species)}
                    {animal.faction ? ` · ${animal.faction}` : ' · Lone wolf'}
                </span>
            </span>
        </>
    );
}
