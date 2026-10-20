import { useCallback, useState } from 'react';

const KEY = 'animalfarm.dismissed';

function read(): string[] {
    try {
        return JSON.parse(
            window.sessionStorage.getItem(KEY) ?? '[]',
        ) as string[];
    } catch {
        return [];
    }
}

/**
 * Notices on the page you've closed, for this visit: a banner, a step, a
 * battle. A new one (a new battle, the next step) shows again.
 */
export function useDismissed(): [
    (key: string) => boolean,
    (key: string) => void,
] {
    const [closed, setClosed] = useState<string[]>(() =>
        typeof window === 'undefined' ? [] : read(),
    );

    const isClosed = useCallback(
        (key: string) => closed.includes(key),
        [closed],
    );
    const close = useCallback((key: string) => {
        setClosed((was) => {
            const next = [...was.filter((k) => k !== key), key].slice(-100);
            try {
                window.sessionStorage.setItem(KEY, JSON.stringify(next));
            } catch {
                // A private window may refuse; it's closed until the page reloads.
            }
            return next;
        });
    }, []);

    return [isClosed, close];
}
