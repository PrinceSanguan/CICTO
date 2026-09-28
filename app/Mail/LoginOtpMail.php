<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in code (client request, 2026-09-25).
 *
 * Sent at once, not queued and not deferred: the person is standing at the
 * login screen waiting for it. The code is in the body only, not the subject
 * line, so a phone's lock-screen preview does not show it to whoever is
 * holding the phone.
 */
class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $code,
        public readonly int $ttlMinutes,
        public readonly ?string $ipAddress,
        public readonly string $userAgent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[CICTO] Your sign-in code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.login-otp',
            text: 'mail.login-otp-text',
            with: [
                'recipientName' => $this->user->name,
                // "482 913": grouped for reading off one screen onto another.
                // Not called `code` -- the public $code property of the same
                // name would take precedence and print it ungrouped.
                'displayCode' => trim(chunk_split($this->code, 3, ' ')),
                'ttlMinutes' => $this->ttlMinutes,
                'requestedAt' => now()->format('F j, Y g:i A'),
                'ipAddress' => $this->ipAddress ?? 'unknown',
                'browser' => $this->browser(),
            ],
        );
    }

    /** A readable name for the browser, from its user-agent string. */
    private function browser(): string
    {
        $agent = $this->userAgent;

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Microsoft Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'a web browser',
        };

        $system = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : "{$browser} on {$system}";
    }
}
