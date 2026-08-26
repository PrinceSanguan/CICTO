<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §23 support ticket, as a Mailable rather than Mail::raw().
 *
 * A real class so it can be asserted on in tests, previewed, and given a
 * reply-to that points at the person who raised it -- which is the difference
 * between a support inbox somebody can answer and one they can only read.
 */
class SupportTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $reporter,
        public readonly string $ticketSubject,
        public readonly string $ticketBody,
        /**
         * Absolute path to the reporter's screenshot, if they attached one.
         *
         * A PATH rather than the UploadedFile: this Mailable is queueable in
         * principle, and an UploadedFile does not survive serialisation --
         * the temp file it points at is gone by the time a worker runs.
         */
        public readonly ?string $screenshotPath = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[CICTO support] '.$this->ticketSubject,
            // From the application's own address, reply-to the reporter.
            // Sending AS the user would fail SPF on any real mail service.
            replyTo: [new Address($this->reporter->email, $this->reporter->name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.support-ticket',
            with: [
                'name' => $this->reporter->name,
                'email' => $this->reporter->email,
                // Branched on the FOREIGN KEY, which is unambiguously
                // int|null, rather than on the relation: office_id is nullable
                // (a user can exist before being assigned to one) but static
                // analysis resolves the relation itself as non-null.
                'office' => $this->reporter->office_id === null
                    ? 'Not assigned'
                    : $this->reporter->office->name,
                'body' => $this->ticketBody,
            ],
        );
    }

    /**
     * The reporter's screenshot, when there is one.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->screenshotPath === null) {
            return [];
        }

        return [
            Attachment::fromPath($this->screenshotPath)
                ->as('screenshot.'.pathinfo($this->screenshotPath, PATHINFO_EXTENSION)),
        ];
    }
}
