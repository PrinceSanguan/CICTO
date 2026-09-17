import type { Auth } from '@/types/auth';
import type { UploadRules } from '@/types/documents';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            uploads: UploadRules;
            [key: string]: unknown;
        };
    }
}
