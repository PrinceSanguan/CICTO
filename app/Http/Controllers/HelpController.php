<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupportTicketRequest;
use App\Mail\SupportTicketMail;
use App\Support\Help\KnowledgeBase;
use App\Support\OutgoingMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * §23 Help & Support: knowledge base, support ticket, contact details.
 *
 * §23 names these three pages but the signed 20-row cost breakdown has no line
 * item for any of them (client question B5). They are built here because the
 * client supplied designs for them, which settles the question -- but the
 * cheapest honest version: static articles, an emailed ticket, and contact
 * details read from config. No CMS, no ticket table, no admin inbox. A stored
 * ticket model with statuses and replies is a separate feature and a re-quote.
 */
class HelpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('help/index', [
            'support' => $this->support(),
        ]);
    }

    public function knowledgeBase(): Response
    {
        return Inertia::render('help/knowledge-base', [
            'articles' => array_map(
                fn (array $article) => [
                    'slug' => $article['slug'],
                    'title' => $article['title'],
                    'summary' => $article['summary'],
                    'category' => $article['category'],
                    'icon' => $article['icon'],
                ],
                KnowledgeBase::articles(),
            ),
            'categories' => KnowledgeBase::CATEGORIES,
        ]);
    }

    public function article(string $slug): Response
    {
        $article = KnowledgeBase::find($slug);

        abort_if($article === null, 404);

        return Inertia::render('help/article', [
            'article' => $article,
            'categories' => KnowledgeBase::CATEGORIES,
            // The password article suppresses its own steps when this server
            // cannot send the email step 4 tells the reader to wait for.
            'support' => $this->support(),
        ]);
    }

    public function contact(): Response
    {
        return Inertia::render('help/contact', [
            'support' => $this->support(),
        ]);
    }

    public function ticket(): Response
    {
        return Inertia::render('help/ticket', [
            'support' => $this->support(),
            'issueTypes' => SupportTicketRequest::ISSUE_TYPES,
        ]);
    }

    public function submitTicket(SupportTicketRequest $request): RedirectResponse
    {
        $user = $request->user();

        /*
         * The design collects a name and email even though both are already on
         * the signed-in account. They are recorded as CONTACT details -- where
         * to reply -- and never used to attribute the ticket: `from` and `name`
         * below still come from the session, so a reporter cannot file a ticket
         * as somebody else by typing their address into the form.
         */
        /*
         * Stored BEFORE anything else happens with the ticket, so the file
         * survives even when the mail transport is missing or fails. The log
         * line below records where it went; a screenshot that exists only
         * inside a failed send is the same problem the log solves for the
         * ticket text.
         *
         * `documents` disk under its own prefix rather than the default local
         * one: it is the disk HostCheckCommand already validates and the one
         * configured for cloud storage in production, so a support screenshot
         * does not quietly become the single thing that only exists on one
         * web node.
         */
        $screenshotPath = null;

        if ($request->hasFile('screenshot')) {
            $stored = $request->file('screenshot')
                ->store('support-tickets/'.now()->format('Y-m'), 'documents');

            /*
             * `store()` answers false when the disk write fails -- a full
             * volume, a bad S3 credential. The ticket still goes through: the
             * reporter's description is the part somebody acts on, and losing
             * their whole report because an attachment could not be saved
             * would be the worse outcome. But it is logged as its own line,
             * because "the screenshot they mention is missing" is exactly the
             * question whoever reads the ticket will have.
             */
            if ($stored === false) {
                Log::channel('stack')->error('Support ticket screenshot could not be stored', [
                    'from' => $user->email,
                ]);
            } else {
                $screenshotPath = $stored;
            }
        }

        $payload = [
            'from' => $user->email,
            'name' => $user->name,
            'office' => $user->office?->name,
            'reply_to' => $request->string('email')->value(),
            'contact_name' => $request->string('name')->value(),
            'tracking_number' => $request->string('tracking_number')->value(),
            'issue_type' => $request->string('issue_type')->value(),
            'subject' => $request->subject(),
            'body' => $request->string('body')->value(),
            'screenshot' => $screenshotPath,
        ];

        $to = (string) config('cicto.support.email');

        // Always recorded, whether or not mail is configured. A ticket that
        // exists only inside a mail transport that is not set up is a ticket
        // nobody will ever read.
        Log::channel('stack')->info('Support ticket raised', $payload);

        if ($to !== '' && OutgoingMail::isConfigured()) {
            /*
             * Caught here rather than left to the handler in bootstrap/app.php.
             *
             * That handler is right for the auth flows, where a failed send
             * means the whole request achieved nothing. This one already did
             * something worth keeping: the ticket was written to the log three
             * lines up, so the honest outcome is "recorded, not delivered" --
             * the same warning the no-transport branch below gives -- and not
             * an error that implies the report was lost.
             *
             * Only transport failures. A bug in the Mailable or its view is a
             * bug and should still surface as one.
             */
            try {
                Mail::to($to)->send(new SupportTicketMail(
                    $user,
                    $payload['subject'],
                    $payload['body'],
                    $screenshotPath === null
                        ? null
                        : Storage::disk('documents')->path($screenshotPath),
                ));
            } catch (TransportExceptionInterface $e) {
                Log::channel('stack')->error('Support ticket email failed', [
                    'to' => $to,
                    'error' => $e->getMessage(),
                ]);

                return back()->with('toast', [
                    'type' => 'warning',
                    'message' => 'Your ticket was recorded, but the email could not be delivered just now. Please contact the office directly on '.config('cicto.support.phone').'.',
                ]);
            }

            return back()->with('toast', [
                'type' => 'success',
                'message' => 'Your ticket has been sent to '.$to.'.',
            ]);
        }

        // Deliberately NOT a success message. Telling somebody their ticket was
        // sent when no mail transport exists is the one outcome this page must
        // never produce.
        return back()->with('toast', [
            'type' => 'warning',
            'message' => 'Outgoing mail is not configured on this server, so your ticket was recorded in the system log but not delivered. Please contact the office directly.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function support(): array
    {
        return [
            'office' => config('cicto.support.office'),
            'email' => config('cicto.support.email'),
            'phone' => config('cicto.support.phone'),
            'address' => config('cicto.support.address'),
            'hours' => config('cicto.support.hours'),
            'hours_detail' => config('cicto.support.hours_detail'),
            'response_window' => config('cicto.support.response_window'),
            'mail_configured' => OutgoingMail::isConfigured(),
        ];
    }
}
