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
                        'body' => 'An office has received the document and is working on it. On the document page itself this stage is named **Under Review**. If the document was submitted to several offices, each one presses **Received** when it arrives and the document moves straight on to the next office on the list, in the order they were listed -- there is no approval step to wait for, and no need to send it on by hand. Only an office Admin can press **Received**, return a document, send it to another office, or sign it.',
                    ],
                    /*
                     * Approved is listed although nothing can reach it, and
                     * deliberately: it is still a STORED status on documents
                     * processed before approval went on 2026-09-03, and
                     * somebody looking at one of those and reaching for this
                     * article has to find the word they are looking at.
                     *
                     * Rejected has no entry of its own since 2026-09-16: the
                     * client asked for the word to go, legacy rejected
                     * documents read Returned in every list, and the Returned
                     * entry says what is different about them.
                     */
                    [
                        'title' => 'Approved',
                        'body' => 'An older status. Documents processed before the approval step was removed may still show it; it means an office had signed off on the document. Nothing new is marked Approved -- offices now press **Received** instead.',
                    ],
                    [
                        'title' => 'Returned',
                        'body' => 'An office sent the document back to be corrected -- to the office that filed it, or to another office it had already passed through, whichever the returning office chose under **Return to**. The reason is required, so it is always in the document history, and the office it was sent back to is notified (and the person who submitted it, when it went back to the office that filed it). In the document list it reads **Returned**, and the Status filter on Track Documents finds it under that name. To fix it, open the document, attach the corrected file, and press **Resubmit**: it goes straight back to the office that returned it, and any offices still queued on its route carry on after that. It stays the same document throughout -- the same control number, the same QR label and one unbroken history. Only an office Admin can return a document, and only while it is with their office. The office returning it can also attach a corrected copy in the same step; it becomes the current version, and the office it was sent back to only needs to check it and press **Resubmit**. Documents refused under the old Reject button also read **Returned** in the list, but that refusal was final: they cannot be resubmitted, so file the corrected document again.',
                    ],
                    [
                        // The design's fifth status is "Released". No screen in
                        // this system uses that word -- the final state is
                        // Completed -- so the entry keeps its place and its
                        // meaning under the name that is actually displayed.
                        'title' => 'Completed',
                        'body' => 'The work is finished and the document is ready for pickup or download. This is a final state. A document that was submitted to several offices becomes Completed by itself when the last office on its list presses **Received** -- there is no separate button for it.',
                    ],
                ],
                'closing' => [
                    'label' => null,
                    'text' => 'Always check the status regularly for updates. The colour on a status pill always matches its wording, so two documents reading Pending will always look the same.',
                ],
            ],
            [
                // The route templates, client request of 2026-09-25.
                'slug' => 'where-your-document-goes',
                'title' => 'Where Your Document Goes',
                'summary' => 'Automatic and Manual Routes',
                'category' => 'tracking',
                'icon' => 'file-text',
                'featured' => false,
                'intro' => 'When you submit a document, the system suggests the offices it should pass through, based on its document type. You can use the suggestion as it is, adjust it, or choose the offices yourself.',
                'steps' => [
                    'On **Submit Document**, choose the **Document Type**. With **Automatic** selected (the default), the suggested route appears under **Department**, numbered in the order the document will travel. Your own office is always number 1, because the document is registered under it.',
                    'Some steps ask you to choose — for example the concerned office for a Memorandum. Pick the office from the list on that step.',
                    'Steps that only apply sometimes, such as the City Accountant on a Travel Order with allowance, have an **Include** box. Tick it when the step applies.',
                    'The suggested route can be changed right there: press **✕** to take an office off, the arrows to move it earlier or later, and **Add an office to the route** to add one. **Reset to suggested** puts the type\'s route back. To build a route from nothing instead, switch to **Manual**.',
                ],
                'sections' => [
                    [
                        'title' => 'The same office twice',
                        'body' => 'A route can come back to an office — a Disbursement Voucher goes to the Treasury before BAC and again at the end for final release. What a route cannot do is list the same office twice in a row, because the document would already be there; the form marks such a step **Already there** and skips it.',
                    ],
                    [
                        'title' => 'The BAC and its members',
                        'body' => 'On a Disbursement Voucher the route goes to the Bids and Awards Committee and then to each member of the committee in turn — the City Accountant, City Planning, the Civil Registrar, CENRO and the City Assessor — before the City Mayor. Each member has its own **Include** box; untick one that does not take part.',
                    ],
                    [
                        'title' => 'Confidential documents',
                        'body' => 'A **Confidential** document goes straight to the City Mayor or HRMO — choose which — the moment it is submitted. Nobody else at your office has to receive it, and nobody else can see it: only you and the people of the office it was sent to. Not even a Super Admin. From there it can only be sent between the City Mayor and HRMO. Its title is also hidden on the public QR page.',
                    ],
                    [
                        'title' => 'Sending to all offices (Broadcast)',
                        'body' => 'An **Executive Order** or a **Memorandum Circular** has a **Broadcast** step. When the document reaches that point, an Admin of the office holding it (or of the office that issued it) opens the document, goes to **Broadcast to all offices** and presses **Broadcast**. Every office gets a notification and can open and download it, while the folder carries on along its route. Offices that only received the broadcast can read it but cannot receive, sign, comment on or archive it. A document is broadcast once, and it cannot be undone.',
                    ],
                    [
                        'title' => 'Steps the system does not do',
                        'body' => 'A few routes include a step shown in italics, such as releasing a clearance to the person who asked for it or sending a reply out. Those happen outside the system.',
                    ],
                ],
                'closing' => [
                    'label' => 'Tip',
                    'text' => 'An office marked **No account yet** has nobody who can receive the document. It can still be on the route, but the document will wait there until an administrator creates an account for that office.',
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
                // The emailed sign-in code, client request of 2026-09-25.
                'slug' => 'your-sign-in-code',
                'title' => 'Your Sign-In Code',
                'summary' => 'The 6-Digit Code Emailed When You Log In',
                'category' => 'login',
                'icon' => 'lock',
                'featured' => false,
                'intro' => 'After you enter your email and password, the system emails you a 6-digit code. Your account opens only after you enter it, so a password alone is not enough to get in.',
                'steps' => [
                    'Enter your email and password on the Login page and press **Login**.',
                    'Open your email and find the message **[CICTO] Your sign-in code**.',
                    'Type the 6-digit code on the **Enter your sign-in code** screen. It signs you in as soon as the last digit is typed.',
                ],
                'sections' => [
                    [
                        'title' => 'If the code does not arrive',
                        'body' => 'Check your Spam folder, then press **Send a new code** (it becomes available after a minute). Only the newest code works. A code expires after 10 minutes.',
                    ],
                    [
                        'title' => 'Wrong code',
                        'body' => 'After five wrong codes the sign-in is cancelled and you start again from your password. If you never asked for a code, somebody else knows your password — change it under **Settings > Security**.',
                    ],
                ],
                'closing' => [
                    'label' => 'Tip',
                    'text' => 'Never share the code with anyone, not even staff who say they are from CICTO. Nobody from the office will ever ask for it.',
                ],
            ],
            [
                // The Security PIN, client request of 2026-09-25.
                'slug' => 'your-security-pin',
                'title' => 'Your Security PIN',
                'summary' => 'The 4-Digit PIN for Opening Documents',
                'category' => 'login',
                'icon' => 'lock',
                'featured' => false,
                'intro' => 'Every document is protected by a 4-digit Security PIN that only you know. It stops anybody else from reading your documents on a computer you left signed in.',
                'steps' => [
                    'The first time you open a document, a **Create your Security PIN** window appears. Choose four digits, type them again to confirm, and press **Save PIN and open**.',
                    'From then on, enter your PIN whenever you open a document. The document opens as soon as the fourth digit is typed.',
                    'If nobody touches the computer for a few minutes, the document locks itself and asks for the PIN again.',
                    'To change your PIN, open **Settings > Security**, enter your password, and choose a new PIN.',
                ],
                'sections' => [
                    [
                        'title' => 'If you forget your PIN',
                        'body' => 'Press **Forgot PIN?** in the PIN window, enter the password you sign in with, and choose a new PIN. If you have forgotten your password too, ask a Super Admin to reset your PIN from Manage Users; you will be asked to create a new one the next time you open a document.',
                    ],
                    [
                        'title' => 'Wrong PIN',
                        'body' => 'After five wrong PINs in a row you are signed out, so nobody can simply try every number. Sign in again with your password and carry on, or use **Forgot PIN?**.',
                    ],
                ],
                'closing' => [
                    'label' => 'Tip',
                    'text' => 'Avoid easy PINs such as 1234 or 1111 — the system will not accept them. Never share your PIN, not even with your office mates; the PIN is what shows that it was you who opened a document.',
                ],
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
