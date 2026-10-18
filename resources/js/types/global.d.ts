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
            isStaff: boolean;
            missionsWaiting: number;
            nextStep: MissionCard | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
