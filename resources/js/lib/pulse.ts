import { useSyncExternalStore } from 'react';
import type { MessageAnimal } from '@/types/game';

/** A pop-up on any game screen: a new message, or news from the bell. */
export type Popup = {
    key: string;
    kind: 'message' | 'notice';
    text: string;
    from?: MessageAnimal;
    url?: string | null;
};

type PulseState = {
    unreadMessages: number | null;
    unreadNotices: number | null;
    /** The animal the chat window is open on. */
    chatWith: number | null;
    popups: Popup[];
};

let state: PulseState = {
    unreadMessages: null,
    unreadNotices: null,
    chatWith: null,
    popups: [],
};
const listeners = new Set<() => void>();

function set(patch: Partial<PulseState>): void {
    state = { ...state, ...patch };
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/** Read one part of the live game state; pick a single value, not a new object. */
export function usePulse<T>(select: (state: PulseState) => T): T {
    return useSyncExternalStore(
        subscribe,
        () => select(state),
        () => select(state),
    );
}

export function openChat(animalId: number): void {
    set({
        chatWith: animalId,
        popups: state.popups.filter((popup) => popup.from?.id !== animalId),
    });
}

export function closeChat(): void {
    set({ chatWith: null });
}

export function chatOpenWith(): number | null {
    return state.chatWith;
}

export function setUnread(messages: number, notices: number): void {
    if (messages !== state.unreadMessages || notices !== state.unreadNotices) {
        set({ unreadMessages: messages, unreadNotices: notices });
    }
}

/** The bell was opened, so its news is read. */
export function noticesSeen(): void {
    set({ unreadNotices: 0 });
}

export function pushPopup(popup: Popup): void {
    if (state.popups.some((shown) => shown.key === popup.key)) {
        return;
    }

    set({ popups: [...state.popups, popup].slice(-4) });
}

export function dismissPopup(key: string): void {
    set({ popups: state.popups.filter((popup) => popup.key !== key) });
}
