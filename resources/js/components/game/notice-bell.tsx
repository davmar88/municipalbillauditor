import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { gameJson, shortWhen } from '@/lib/game';
import { noticesSeen, usePulse } from '@/lib/pulse';
import { cn } from '@/lib/utils';
import { notices as noticesRoute } from '@/routes/play';
import { read } from '@/routes/play/notices';

type Notice = {
    id: number;
    kind: string;
    text: string;
    url: string | null;
    at: string;
    read: boolean;
};

/** The bell at the top: game news, newest first, read once you open it. */
export function NoticeBell() {
    const { unreadNotices } = usePage().props;
    const live = usePulse((state) => state.unreadNotices);
    const count = live ?? unreadNotices ?? 0;
    const [notices, setNotices] = useState<Notice[] | null>(null);
    const [failed, setFailed] = useState(false);

    const open = async () => {
        const result = await gameJson<{ notices: Notice[]; unread: number }>(
            noticesRoute.url(),
        );

        if (!result.ok) {
            setFailed(true);

            return;
        }

        setFailed(false);
        setNotices(result.data.notices);

        if (result.data.unread > 0) {
            noticesSeen();
            await gameJson(read.url(), 'POST');
        }
    };

    return (
        <DropdownMenu
            onOpenChange={(isOpen) => {
                if (isOpen) {
                    void open();
                }
            }}
        >
            <DropdownMenuTrigger
                className="relative flex size-10 items-center justify-center rounded-full text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={count > 0 ? `News, ${count} new` : 'News'}
            >
                <Bell className="size-5" aria-hidden />
                {count > 0 && (
                    <span
                        className="absolute top-0.5 right-0 min-w-5 rounded-full bg-orange-500 px-1 text-center text-[11px] leading-5 font-bold text-white"
                        aria-hidden
                    >
                        {count > 99 ? '99+' : count}
                    </span>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-[min(30rem,70svh)] w-[min(22rem,calc(100vw-1.5rem))] overflow-y-auto"
            >
                <DropdownMenuLabel>News from the valley</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {notices === null && !failed && (
                    <p className="px-2 py-3 text-sm text-muted-foreground">
                        Looking…
                    </p>
                )}
                {failed && (
                    <p className="px-2 py-3 text-sm text-muted-foreground">
                        The news didn't load. Try again in a moment.
                    </p>
                )}
                {notices?.length === 0 && (
                    <p className="px-2 py-3 text-sm text-muted-foreground">
                        Nothing yet. Raids on your faction's land, sales in your
                        shop, requests to join and new laws show up here.
                    </p>
                )}
                {notices?.map((notice) => (
                    <DropdownMenuItem
                        key={notice.id}
                        onSelect={() => notice.url && router.visit(notice.url)}
                        className={cn(
                            'flex flex-col items-start gap-0.5 py-2',
                            !notice.read && 'bg-primary/10',
                        )}
                    >
                        <span className="text-sm whitespace-normal">
                            {notice.text}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {shortWhen(notice.at)}
                        </span>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
