A support ticket has been raised in the CICTO Document Tracking System.

{{-- Raw, not escaped. This is a text/plain Mailable (SupportTicketMail's
     Content declares `text:`), so Blade's `{{ }}` is not protecting anything
     here -- it just turns an apostrophe in a name into &#039; and an ampersand
     in the report into &amp; in the message a human reads. If this view is ever
     given an HTML twin, the HTML one must go back to `{{ }}`. --}}
From:   {!! $name !!} <{!! $email !!}>
Office: {!! $office !!}

{!! $body !!}

--
Reply to this email to answer the reporter directly.
