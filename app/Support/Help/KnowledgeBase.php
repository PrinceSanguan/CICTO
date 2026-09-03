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
 * Each article is shaped the way the client's article designs are laid out:
 * a one-line intro, then either numbered `steps` or titled `sections`, then an
 * optional closing note. The page renders exactly those parts and nothing else.
 *
 * Copy follows the supplied designs closely. Where a design named a control or
 * a status this system does not have, the article uses the name a reader will
 * actually see on screen -- a help page that sends somebody hunting for a
 * "Track button" or a "Released" status generates the support ticket it was
 * written to prevent. Those specific departures are commented where they occur.
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
                'intro' => 'Tracking your document allows you to check the progress of your request or application.',
                'steps' => [
                    // The design says "Document Tracking page"; the menu item
                    // reads "Track Documents", so the article uses that.
                    'Open **Track Documents** from the top menu.',
                    // The design calls it a Tracking Number. Every screen and
                    // every printed label in this system calls it a Control
                    // Number, so both names appear once and then only the real
                    // one is used.
                    'Enter the **control number** — the tracking number printed on your receipt when the document was submitted — in the search box.',
                    'Press **Search**. Searching is case-insensitive, so `ocm-2026-00001` finds `OCM-2026-00001`, and you can search by title instead.',
                    'Your document\'s status and current processing stage appear in the list.',
                    'Click **View** on the row to see where the document is now, how long it has been there, and every office it has passed through.',
                ],
                'closing' => [
                    'label' => 'Tip',
                    'text' => 'Always keep your control number safe so you can check your document anytime. Filters live in the address bar, so you can bookmark a filtered list or send the link to a colleague and they will see exactly what you see.',
                ],
            ],
            [
                'slug' => 'document-status-explained',
                'title' => 'Document Status Explained',
                'summary' => 'Understanding Document Status',
                'category' => 'tracking',
                'icon' => 'layers',
                'featured' => true,
                'intro' => 'Below are the common document statuses in the system:',
                'sections' => [
                    [
                        'title' => 'Pending',
                        'body' => 'Your document has been submitted but no office has picked it up yet.',
                    ],
                    [
                        'title' => 'In Process',
                        'body' => 'An office has received the document and is working on it. On the document page itself this stage is named **Under Review**. If the document was submitted to several offices, each one presses **Received** when it arrives and the document moves straight on to the next office on the list -- there is no approval step to wait for.',
                    ],
                    /*
                     * Approved and Rejected are still listed, and deliberately.
                     *
                     * Neither is reachable any more -- the client removed the
                     * approval step on 2026-09-03 -- but both are still STORED
                     * statuses on every document processed before that date, and
                     * the status filter still offers Rejected as one of §8's four
                     * client-facing names. Somebody looking at an older document
                     * and reaching for this article has to find the word they are
                     * looking at, so both entries say what they mean AND that
                     * nothing new arrives in them.
                     */
                    [
                        'title' => 'Approved',
                        'body' => 'An older status. Documents processed before the approval step was removed may still show it; it means an office had signed off on the document. Nothing new is marked Approved -- offices now press **Received** instead.',
                    ],
                    [
                        'title' => 'Rejected',
                        'body' => 'An older status. It means the document did not meet the required criteria, and the reason is recorded in the document history. Nothing new is marked Rejected.',
                    ],
                    [
                        // The design's fifth status is "Released". No screen in
                        // this system uses that word -- the final state is
                        // Completed -- so the entry keeps its place and its
                        // meaning under the name that is actually displayed.
                        'title' => 'Completed',
                        'body' => 'The work is finished and the document is ready for pickup or download. This is a final state.',
                    ],
                ],
                'closing' => [
                    'label' => null,
                    'text' => 'Always check the status regularly for updates. The colour on a status pill always matches its wording, so two documents reading Pending will always look the same.',
                ],
            ],
            [
                'slug' => 'how-to-scan-a-qr-code',
                'title' => 'How to Scan a QR Code',
                'summary' => 'Learn How to Scan Documents',
                'category' => 'qr',
                'icon' => 'qr-code',
                'featured' => true,
                'intro' => 'QR codes allow quick access to document information.',
                'steps' => [
                    'Open the camera app on your smartphone or tablet.',
                    'Point the camera at the QR code printed on the document or screen.',
                    'Wait for the notification or link to appear.',
                    'Tap the link to open the document information or verification page.',
                    'Review the details displayed on the system.',
                ],
                'sections' => [
                    [
                        'title' => 'Scanning from a counter terminal',
                        'body' => 'Open **Scan QR Code** in the menu, put the cursor in the box, and scan with a USB barcode reader — it types the code and submits by itself. You can also type the code printed under the QR square.',
                    ],
                    [
                        'title' => 'If the in-page camera is unavailable',
                        // Kept from the previous article because it is the
                        // single most common QR support call, and the design's
                        // generic note does not explain it.
                        'body' => 'The camera button inside CICTO only works when the site is served over **HTTPS**. Browsers block camera access on insecure addresses, so at an address starting `http://192.168.` it will say it is unavailable. That is a browser rule, not a setting. Use your phone\'s own camera app, a barcode reader, or type the code.',
                    ],
                ],
                'closing' => [
                    'label' => 'Note',
                    'text' => 'Some devices may require a QR scanner app if the camera does not automatically scan QR codes.',
                ],
            ],
            [
                'slug' => 'how-to-update-my-profile',
                'title' => 'How to Update My Profile',
                'summary' => 'Edit Your Personal Information',
                'category' => 'account',
                'icon' => 'user',
                'featured' => true,
                'intro' => 'Keeping your profile updated ensures accurate records in the system.',
                'steps' => [
                    'Log in to your account.',
                    'Open **Settings → Profile** from your account menu.',
                    'Update the necessary information — your name, email address and contact number.',
                    'Click **Save** to apply the updates.',
                ],
                'sections' => [
                    [
                        'title' => 'What you cannot change here',
                        'body' => 'Your **office** and your **role** are set by an administrator, because they decide which documents you can see. Contact support if either is wrong.',
                    ],
                    [
                        'title' => 'Changing your email address',
                        'body' => 'You will be asked to verify the new address before the change takes effect, so use one you can open.',
                    ],
                ],
                'closing' => [
                    'label' => 'Reminder',
                    'text' => 'Make sure all information is correct before saving. Your name appears on the audit trail and on every document you sign, so keep it as it should read on a municipal record.',
                ],
            ],
            [
                'slug' => 'i-forgot-my-password',
                'title' => 'I Forgot My Password',
                'summary' => 'Reset Your Password Easily',
                'category' => 'login',
                'icon' => 'lock',
                'featured' => true,
                'intro' => 'If you forget your password, you can reset it in a few simple steps.',
                'steps' => [
                    'Go to the Login page.',
                    'Click **Forgot Password?** next to the Password field.',
                    'Enter your registered email address.',
                    'Check your email for the password reset link.',
                    'Click the link and create a new password.',
                    'Log in again using your new password.',
                ],
                'sections' => [
                    [
                        'title' => 'If no email arrives',
                        'body' => 'Check the junk folder first. The reset link is single-use and expires — if it has expired, request another. For security the page gives the same response whether or not the address is registered, so it cannot be used to find out who has an account.',
                    ],
                ],
                'closing' => [
                    'label' => 'Tip',
                    'text' => 'Use a strong password that includes letters, numbers, and symbols.',
                ],
                /*
                 * Rendered above the steps when the server has no mail
                 * transport configured (client question B3). Steps 3 to 6 above
                 * cannot happen -- the Forgot Password page refuses rather than
                 * pretending -- and a help article that calmly instructs
                 * somebody to wait for an email that will never arrive is worse
                 * than no article at all.
                 *
                 * It now names a procedure rather than only withdrawing one.
                 * CICTO answered B3 on 2026-08-20 by declining to supply SMTP
                 * credentials and asking for an administrator-set password
                 * instead, so on this deployment that IS the reset procedure,
                 * not a workaround for the absence of one.
                 */
                'unavailable_without_mail' => 'This server cannot send email yet, so the steps below will not work: no reset link can be sent. Ask a Super Admin to set a new password for you instead — they can do it from Manage Users while you wait — then change it yourself under Settings > Security once you are signed in.',
            ],
            [
                'slug' => 'common-errors',
                'title' => 'Common Errors',
                'summary' => 'Solutions and Fixes',
                'category' => 'errors',
                'icon' => 'alert-triangle',
                'featured' => true,
                'intro' => 'Here are some common issues and how to fix them:',
                'sections' => [
                    [
                        'title' => 'Invalid Information',
                        'body' => 'Double-check the details you entered before submitting. Fields marked with a red asterisk are required, including the file upload on Submit Document.',
                    ],
                    [
                        'title' => 'File Upload Error',
                        'body' => 'Make sure the file format and size meet the system requirements. Very large files can be cut off by the server before the system sees them, and SVG files are refused on purpose.',
                    ],
                    [
                        'title' => 'Slow Loading or System Error',
                        'body' => 'Refresh the page or try again later. Exports of a very wide date range are built while you wait, so narrow the range or use the CSV export, which has no row limit.',
                    ],
                    [
                        'title' => 'Login Problems',
                        'body' => 'Ensure your email and password are correct, or reset your password if needed. An account an administrator has deactivated will refuse to sign in whatever the password.',
                    ],
                    [
                        // Quoted verbatim from StaleWorkflowStateException.
                        // UserFacingFailureTest asserts the two cannot drift
                        // apart, because this article promising one sentence
                        // while the app showed another is a real bug that
                        // shipped once already.
                        'title' => '"This document has already moved on."',
                        'body' => 'Somebody else acted on it while your page was open. Refresh and look at the current status before acting again. Nothing was lost.',
                    ],
                    [
                        'title' => '"You have already signed this version."',
                        'body' => 'A signature covers one exact file version. If a corrected file has since been uploaded, sign that one instead.',
                    ],
                    [
                        'title' => 'A page says you do not have access',
                        'body' => 'Documents are visible to the office that raised them and the offices they have passed through. If you need access, ask an administrator rather than a colleague to forward you a link.',
                    ],
                ],
                'closing' => [
                    'label' => null,
                    'text' => 'If the problem continues, contact system support or the administrator for assistance.',
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
