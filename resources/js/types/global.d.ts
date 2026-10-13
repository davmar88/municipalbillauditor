import type { Organisation } from '@/types/auditor';
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
            organisation: Organisation | null;
            organisations: { id: number; name: string; kindLabel: string }[];
            isStaff: boolean;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
