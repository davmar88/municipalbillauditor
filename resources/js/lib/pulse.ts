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
    /** The animals you've been chatting with, kept as heads in the corner. */
    heads: MessageAnimal[];
    /** Heads with a message you haven't opened yet. */
    unreadHeads: number[];
    /** Bumped when a message arrives for the open chat, so it fetches at once. */
    chatTick: number;
};

const HEADS_KEY = 'animalfarm.chat-heads';
const MOST_HEADS = 5;

function storedHeads(): MessageAnimal[] {
    try {
        const raw = window.localStorage.getItem(HEADS_KEY);

        return raw
            ? (JSON.parse(raw) as MessageAnimal[]).slice(0, MOST_HEADS)
            : [];
    } catch {
        return [];
    }
}

function storeHeads(heads: MessageAnimal[]): void {
    try {
        window.localStorage.setItem(HEADS_KEY, JSON.stringify(heads));
    } catch {
        // Private windows may refuse; the heads just don't outlive the page.
    }
}

let state: PulseState = {
    unreadMessages: null,
    unreadNotices: null,
    chatWith: null,
    popups: [],
    heads: typeof window === 'undefined' ? [] : storedHeads(),
    unreadHeads: [],
    chatTick: 0,
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

export function openChat(animalId: number, animal?: MessageAnimal): void {
    set({
        chatWith: animalId,
        popups: state.popups.filter((popup) => popup.from?.id !== animalId),
        unreadHeads: state.unreadHeads.filter((id) => id !== animalId),
    });

    if (animal) {
        keepHead(animal);
    }
}

/** Fold the chat window down to its head in the corner. */
export function closeChat(): void {
    set({ chatWith: null });
}

/** Put an animal's head at the front of the corner, or refresh it there. */
export function keepHead(animal: MessageAnimal): void {
    const heads = [
        animal,
        ...state.heads.filter((head) => head.id !== animal.id),
    ].slice(0, MOST_HEADS);
    storeHeads(heads);
    set({ heads });
}

/** Close a chat for good: its head leaves the corner. */
export function dropHead(animalId: number): void {
    const heads = state.heads.filter((head) => head.id !== animalId);
    storeHeads(heads);
    set({
        heads,
        unreadHeads: state.unreadHeads.filter((id) => id !== animalId),
        chatWith: state.chatWith === animalId ? null : state.chatWith,
    });
}

/** A message came in: its sender's head moves to the front, marked unread unless its chat is open. */
export function messageFrom(animal: MessageAnimal): void {
    keepHead(animal);

    if (state.chatWith === animal.id) {
        set({ chatTick: state.chatTick + 1 });
    } else if (!state.unreadHeads.includes(animal.id)) {
        set({ unreadHeads: [...state.unreadHeads, animal.id] });
    }
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
