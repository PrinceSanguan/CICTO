/**
 * Every string on the landing page, transcribed verbatim from the client's
 * Figma. Copy edits during a review should only touch this file.
 *
 * Note the repeated body lines below ("Quickly scan documents with QR codes"
 * and "View complete document history" each appear twice). That is how the
 * Figma reads and it is reproduced deliberately -- fixing it here is a
 * one-line change once the client confirms the intended wording.
 */

export const ORG = {
    acronym: 'CICTO',
    system: 'CICTO Document Tracking System',
} as const;

export const HERO = {
    eyebrow: 'Track, Manage, and Monitor',
    /** Two lines in the design, broken after "Documents". */
    headingLine1: 'Your Documents',
    headingLine2: 'Efficiently',
    /** Two lines in the design, broken after "Document". */
    subLine1: 'Welcome to CICTO Document',
    subLine2: 'Tracking System',
    /**
     * The hero button's label. It read a bare "Login" until the client's
     * 2026-08-25 comp spelled it out; the closing band keeps its own shorter
     * "Login Now" (see GET_STARTED) rather than repeating this one.
     */
    action: 'Login to Your Account',
} as const;

export type FeatureIcon =
    'tracking' | 'qr' | 'reports' | 'folder' | 'shield' | 'analytics';

export type Feature = {
    icon: FeatureIcon;
    title: string;
    body: string;
};

/** The three cards in the panel overlapping the bottom of the hero. */
export const HERO_FEATURES: readonly Feature[] = [
    {
        icon: 'tracking',
        title: 'Real-Time Tracking',
        body: 'Monitor document movement instantly',
    },
    {
        icon: 'qr',
        title: 'QR Code Scanning',
        body: 'Quickly scan documents with QR codes',
    },
    {
        icon: 'reports',
        title: 'Detailed Reports',
        body: 'View complete document history',
    },
] as const;

export const WHY = {
    heading: 'Why Choose CICTO Document Tracking System?',
    items: [
        {
            icon: 'folder',
            title: 'Efficient Management',
            body: 'Quickly scan documents with QR codes',
        },
        {
            icon: 'shield',
            title: 'Secure & Reliable',
            body: 'View complete document history',
        },
        {
            icon: 'qr',
            title: 'Easy QR Scanning',
            body: 'Quickly scan documents with QR codes',
        },
        {
            icon: 'analytics',
            title: 'Detailed Analytics',
            body: 'View complete document history',
        },
    ] as readonly Feature[],
} as const;

/**
 * The closing call to action, painted over the skyline band.
 *
 * The comp gives the button its own label -- "Login Now", not the hero's
 * "Login" -- so it is transcribed rather than shared with HERO. Only the
 * signed-out label lives here: the signed-in state reuses the hero's "Logout",
 * which is built in pages/welcome.tsx for both slots at once.
 */
export const GET_STARTED = {
    heading: 'Ready to Get Started?',
    action: 'Login Now',
} as const;
