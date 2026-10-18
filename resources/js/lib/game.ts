import { router } from '@inertiajs/react';
import type { RequestPayload } from '@inertiajs/core';
import type { Species } from '@/types/game';

const EMOJI: Record<Species, string> = {
    pig: '🐷',
    dog: '🐶',
    horse: '🐴',
    sheep: '🐑',
    goat: '🐐',
    hen: '🐔',
    donkey: '🫏',
    raven: '🐦',
};

/** The materials animals gather, and where. */
export const MATERIALS: { name: string; from: string }[] = [
    { name: 'wood', from: 'Wildwood' },
    { name: 'straw', from: 'Big Barn, Stables' },
    { name: 'stone', from: 'Quarry' },
    { name: 'clay', from: 'Duck Pond, Old Well' },
    { name: 'wool', from: 'Sheepfold' },
    { name: 'dye', from: 'Orchard' },
    { name: 'eggs', from: 'Henhouse' },
    { name: 'honey', from: 'Thornbury Farm' },
    { name: 'herbs', from: 'Brookside Farm' },
    { name: 'iron', from: 'Willowby Farm' },
];

/** The face an animal wears on the map. */
export function speciesEmoji(species: Species): string {
    return EMOJI[species] ?? '🐾';
}

/** "14:05", in the valley's own time. */
export function clockTime(iso: string): string {
    return new Intl.DateTimeFormat('en-ZA', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Africa/Johannesburg',
    }).format(new Date(iso));
}

/** "in 2 h 5 min", "in 12 min", "now". */
export function fromNow(iso: string, now: number = Date.now()): string {
    const minutes = Math.round((new Date(iso).getTime() - now) / 60000);

    if (minutes <= 0) {
        return 'now';
    }

    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;

    if (days > 0) {
        return `in ${days} ${days === 1 ? 'day' : 'days'}${hours > 0 ? ` ${hours} h` : ''}`;
    }

    return hours > 0 ? `in ${hours} h ${rest} min` : `in ${rest} min`;
}

/** "14:05" for today, "12 Oct" for earlier days. */
export function shortWhen(iso: string, now: Date = new Date()): string {
    const day = (date: Date) =>
        new Intl.DateTimeFormat('en-ZA', {
            dateStyle: 'short',
            timeZone: 'Africa/Johannesburg',
        }).format(date);

    if (day(new Date(iso)) === day(now)) {
        return clockTime(iso);
    }

    return new Intl.DateTimeFormat('en-ZA', {
        day: 'numeric',
        month: 'short',
        timeZone: 'Africa/Johannesburg',
    }).format(new Date(iso));
}

export function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Post a game action and stay where you are on the page. */
export function act(
    url: string,
    data: RequestPayload = {},
    method: 'post' | 'put' | 'delete' = 'post',
): void {
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        preserveState: true,
    });
}

/**
 * Ask the game to translate a message into your language. Returns the
 * translation, or the reason it couldn't be done as an error.
 */
export async function fetchTranslation(
    url: string,
): Promise<{ translation: string } | { error: string }> {
    const token = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
    const data = (await response.json()) as {
        translation?: string;
        message?: string;
    };

    if (!response.ok || !data.translation) {
        return {
            error: data.message ?? "That message couldn't be translated.",
        };
    }

    return { translation: data.translation };
}

/**
 * A JSON request from a game screen, with the session's CSRF token. Game
 * refusals come back as the error message to show.
 */
export async function gameJson<T>(
    url: string,
    method: 'GET' | 'POST' = 'GET',
    body?: Record<string, unknown>,
): Promise<
    { ok: true; data: T } | { ok: false; error: string; status: number }
> {
    const token = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );

    try {
        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
            credentials: 'same-origin',
        });
        const data = (await response.json().catch(() => ({}))) as T & {
            message?: string;
        };

        if (!response.ok) {
            return {
                ok: false,
                status: response.status,
                error:
                    data.message ?? "That didn't work. Try again in a moment.",
            };
        }

        return { ok: true, data };
    } catch {
        return { ok: false, status: 0, error: 'You seem to be offline.' };
    }
}
