import type { MissionCard, PlayerSummary } from '@/types/game';
import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            player: PlayerSummary | null;
            unreadMessages: number;
            unreadNotices: number;
            isStaff: boolean;
            isAdmin: boolean;
            /** An owner playing as this player, to see a bug. */
            impersonating: boolean;
            /** Cash-outs waiting for the owner, for the admin badge. */
            cashoutsWaiting: number;
            missionsWaiting: number;
            nextStep: MissionCard | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
