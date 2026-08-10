import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const STANDALONE_PAGES = new Set([
    'welcome',
    'documents/scan-public',
    'documents/scan-not-found',
    'privacy',
    'signatures/verify',
]);

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        // Pages reachable with no session, which therefore must not mount the
        // authenticated app shell:
        //   - the landing page
        //   - §7 QR scan results, opened by couriers and citizens
        //   - the RA 10173 privacy notice, linked from those scan results
        //   - §15 signature verification, reached from a printed certificate
        if (STANDALONE_PAGES.has(name)) {
            return null;
        }

        if (name.startsWith('auth/')) {
            return AuthLayout;
        }

        if (name.startsWith('settings/')) {
            return [AppLayout, SettingsLayout];
        }

        return AppLayout;
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
