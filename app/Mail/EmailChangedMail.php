<?php

namespace App\Mail;

use App\Support\LoginOtp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the OLD address when a person moves their account to a new one
 * (client request, 2026-09-28: each person may change their own email).
 *
 * The address is where the sign-in code goes, so moving it is how an account
 * is taken over: whoever holds the new inbox holds the account. This is the
 * one message the real owner still receives after that -- and on the shared
 * office accounts, whose old address is the CICTO office's own inbox, it is
 * how CICTO learns which office has moved to which address.
 *
 * The new address is masked: the old inbox may be shared, and the notice has
 * to say that something changed without handing a stranger the new one.
 */
class EmailChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $newAddress,
        public readonly ?string $ipAddress,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[CICTO] Your sign-in email was changed');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.email-changed',
            text: 'mail.email-changed-text',
            with: [
                'name' => $this->recipientName,
                'maskedAddress' => LoginOtp::maskEmail($this->newAddress),
                'changedAt' => now()->format('F j, Y g:i A'),
                'fromIp' => $this->ipAddress ?? 'unknown',
            ],
        );
    }
}
