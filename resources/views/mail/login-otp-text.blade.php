{{-- Raw, not escaped: the text/plain part. The HTML part escapes. --}}
Your sign-in code

Hello {!! $recipientName !!},

Enter this code on the sign-in screen to finish signing in:

    {!! $displayCode !!}

It expires in {!! $ttlMinutes !!} {!! $ttlMinutes === 1 ? 'minute' : 'minutes' !!} and works once. Never share it - CICTO staff will never ask you for it.

Requested: {!! $requestedAt !!}
From: {!! $browser !!}
IP address: {!! $ipAddress !!}

--
Did not try to sign in? Somebody has your password. Sign in and change it under Settings > Security, or ask your administrator to reset it.
