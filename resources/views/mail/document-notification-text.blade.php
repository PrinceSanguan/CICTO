{{-- Raw, not escaped: this is the text/plain part, where {{ }} would only turn
     an apostrophe into &#039;. The HTML part escapes. See support-ticket.blade.php. --}}
{!! $headline !!}

Hello {!! $recipientName !!},

{!! $intro !!}

@foreach ($details as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
@if (filled($remarks))

{!! $remarksLabel !!}:
{!! $remarks !!}
@endif

Open the document: {!! $url !!}

--
You received this email because {!! $reason !!} in the CICTO Document Tracking System.
