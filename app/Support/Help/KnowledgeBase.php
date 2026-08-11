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
                    'Press **Search**. Searching is case-insensitive, so `mo-2026-00001` finds `MO-2026-00001`, and you can search by title instead.',
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
                        'body' => 'Your document has been submitted but is still waiting to be processed. A document returned to an office for correction also shows as Pending.',
                    ],
                    [
                        'title' => 'In Process',
                        'body' => 'An office has received the document and is checking or verifying it. On the document page itself this stage is named **Under Review**, and then **Approved** once it has passed verification and is on its way to completion.',
                    ],
                    [
                        'title' => 'Approved',
                        'body' => 'The document has passed verification. It stays In Process in the list until the work is finished.',
                    ],
                    [
                        'title' => 'Rejected',
                        'body' => 'The document did not meet the required criteria. You may need to resubmit. The reason is recorded in the document history.',
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
                 * transport configured (client question B3). Until SMTP details
                 * are supplied, step 4 above cannot happen -- and a help article
                 * that calmly instructs somebody to wait for an email that will
                 * never arrive is worse than no article at all.
                 */
                'unavailable_without_mail' => 'Password reset is not available on this server yet, because outgoing email has not been configured. Ask your administrator to reset your password for you until it is.',
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
