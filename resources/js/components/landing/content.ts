import documents from '@/routes/documents';
import help from '@/routes/help';
import reports from '@/routes/reports';

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

/**
 * The nav's four items, from the Figma.
 *
 * Three of them are FEATURES, not page sections, and they now say so. They were
 * all in-page anchors -- `#home`, `#track`, `#reports`, `#help` -- but only two
 * of those targets ever existed: `#home` on the hero, and `#reports`, which was
 * pinned to the "Why Choose CICTO" block and so scrolled to something that is
 * not a report. `#track` and `#help` matched nothing at all, which is what the
 * client reported on 2026-08-17: clicking them did nothing.
 *
 * Rather than invent three marketing sections the Figma does not have, the
 * three feature items now open the real screens. A visitor with no session is
 * sent to the login page and lands on the screen they asked for once they sign
 * in -- Laravel stores the intended URL and RoleAwareLoginResponse replays it.
 *
 * `kind` decides the element: 'anchor' scrolls within the page, 'route' is an
 * Inertia visit. See SiteNav.
 */
export const NAV = [
    { label: 'Home', href: '#home', kind: 'anchor', current: true },
    {
        label: 'Track Documents',
        href: documents.index().url,
        kind: 'route',
        current: false,
    },
    {
        label: 'Reports',
        href: reports.index().url,
        kind: 'route',
        current: false,
    },
    { label: 'Help', href: help.index().url, kind: 'route', current: false },
] as const;

export const HERO = {
    eyebrow: 'Track, Manage, and Monitor',
    /** Two lines in the design, broken after "Documents". */
    headingLine1: 'Your Documents',
    headingLine2: 'Efficiently',
    /** Two lines in the design, broken after "Document". */
    subLine1: 'Welcome to CICTO Document',
    subLine2: 'Tracking System',
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
