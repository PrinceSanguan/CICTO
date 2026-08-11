<?php

namespace App\Support\Help;

/**
 * §23's knowledge base.
 *
 * Static PHP rather than a database table or a CMS, and that is the whole
 * design: §23 is not costed in the signed breakdown (client question B5), and
 * an editor, a revision history and a permission model would be a separate
 * feature at a separate price. Articles change when somebody edits this file
 * and deploys, which for six help pages is the honest trade.
 *
 * Categories match the chips in the client's design exactly.
 */
final class KnowledgeBase
{
    public const CATEGORIES = [
        'account' => 'Account Issues',
        'tracking' => 'Documents Tracking',
        'qr' => 'QR Code',
        'login' => 'Login & Password',
        'errors' => 'Common Errors',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function articles(): array
    {
        return [
            [
                'slug' => 'how-to-track-your-document',
                'title' => 'How to Track Your Document',
                'summary' => 'Step-by-Step Guide to Tracking',
                'category' => 'tracking',
                'icon' => 'file-text',
                'featured' => true,
                'body' => [
                    'Open **Track Documents** from the top menu. The list shows every document you are allowed to see: the ones you submitted, and the ones that have passed through your office.',
                    'Type a control number in the search box. Search is case-insensitive, so `mo-2026-00001` finds `MO-2026-00001`. You can also search by title.',
                    'Use the **Status** dropdown to narrow the list to Pending, In Process, Rejected or Completed.',
                    'Click **View** on any row to see where the document is now, how long it has been there, and every office it has passed through.',
                    'The filters live in the address bar, so you can bookmark a filtered view or send the link to a colleague and they will see the same list.',
                ],
            ],
            [
                'slug' => 'document-status-explained',
                'title' => 'Document Status Explained',
                'summary' => 'Understanding Document Status',
                'category' => 'tracking',
                'icon' => 'layers',
                'featured' => true,
                'body' => [
                    '**Pending** — the document has been registered, or returned to an office for correction. Nobody has started on it yet.',
                    '**In Process** — an office has received it and is working on it, or it has been approved and is on its way to completion.',
                    '**Completed** — the work is finished. This is a final state.',
                    '**Rejected** — the document was refused. This is also final, and the reason is recorded in the history.',
                    'The colour on the status pill always matches the word. If two rows say Pending, they will look the same.',
                ],
            ],
            [
                'slug' => 'how-to-scan-a-qr-code',
                'title' => 'How to Scan a QR Code',
                'summary' => 'Learn How to Scan Documents',
                'category' => 'qr',
                'icon' => 'qr-code',
                'featured' => true,
                'body' => [
                    'Every registered document gets a QR label. Scanning it opens that document\'s status page.',
                    '**With a USB barcode scanner** — open **Scan QR Code**, make sure the cursor is in the box, and scan. The scanner types the code and submits by itself. This needs no permissions and works on any browser.',
                    '**With a phone camera** — press *Scan with the camera* on the same page and point the rear camera at the label.',
                    'The camera only works when the site is served over **HTTPS**. Browsers block camera access on insecure addresses, so if the system is reached at an address starting `http://192.168.`, the camera option will say it is unavailable. That is a browser rule, not a setting we can change. Use the scanner or type the code instead.',
                    'You can also just type the code from under the QR square into the box.',
                ],
            ],
            [
                'slug' => 'how-to-update-my-profile',
                'title' => 'How to Update My Profile',
                'summary' => 'Edit Your Personal Information',
                'category' => 'account',
                'icon' => 'user',
                'featured' => true,
                'body' => [
                    'Open **Settings → Profile** from your account menu.',
                    'You can change your name and email address. Your name is what appears on the audit trail and on any document you sign, so keep it as it should read on a municipal record.',
                    'Changing your email address will ask you to verify the new one before it takes effect.',
                    'Your **office** and your **role** are set by an administrator and cannot be changed here. Contact support if either is wrong.',
                ],
            ],
            [
                'slug' => 'i-forgot-my-password',
                'title' => 'I Forgot My Password',
                'summary' => 'Reset Your Password Easily',
                'category' => 'login',
                'icon' => 'lock',
                'featured' => true,
                'body' => [
                    'On the login page, click **Forget Password?** next to the Password field.',
                    'Enter the email address your account uses. If it matches an account, a reset link is sent to it.',
                    'The link is single-use and expires. If it has expired, request another one.',
                    'For security the page gives the same response whether or not the address is registered, so it cannot be used to find out who has an account.',
                    'If no email arrives, check the junk folder first, then contact support — the system may not have outgoing mail configured yet.',
                ],
            ],
            [
                'slug' => 'common-errors',
                'title' => 'Common Errors',
                'summary' => 'Solutions and Fixes',
                'category' => 'errors',
                'icon' => 'alert-triangle',
                'featured' => true,
                'body' => [
                    '**"This document has already moved on."** — somebody else acted on it while your page was open. Refresh and look at the current status before acting again. Nothing was lost.',
                    '**"You have already signed this version."** — a signature covers one exact file version. If a corrected file has since been uploaded, sign that one instead.',
                    '**The file will not upload.** — check the size. Very large files can be cut off by the server before the system sees them. SVG files are refused on purpose.',
                    '**"This file is no longer available." (410)** — the document is fine, but that intermediate version\'s contents were cleared under the retention policy. Version 1 and the current version are always kept.',
                    '**A page says you do not have access.** — documents are visible to the office that raised them and the offices they have passed through. If you need access, ask an administrator rather than a colleague to forward you a link.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::articles() as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }

        return null;
    }
}
