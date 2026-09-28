<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
</head>
{{--
    Hand-written HTML with inline styles, not a markdown Mailable. Titles and
    remarks are typed by staff, and markdown would read an underscore in a file
    name as emphasis or a pipe as a table column. Everything user-typed goes
    through {{ }} here -- this is the HTML twin that support-ticket.blade.php
    warns about.
--}}
<body style="margin: 0; padding: 0; background-color: #eff6fe; font-family: Arial, Helvetica, sans-serif; color: #1f2a44;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #eff6fe; padding: 24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="background-color: #2d6fcb; padding: 16px 24px; color: #ffffff; font-size: 14px; font-weight: bold;">
                            CICTO Document Tracking System
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px;">
                            <h1 style="margin: 0 0 12px; font-size: 20px; color: #1f2a44;">{{ $headline }}</h1>

                            <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">Hello {{ $recipientName }},</p>
                            <p style="margin: 0 0 20px; font-size: 14px; line-height: 1.5;">{{ $intro }}</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e4eaf2; border-radius: 6px; font-size: 14px;">
                                @foreach ($details as $label => $value)
                                    <tr>
                                        <td style="padding: 8px 12px; width: 40%; font-weight: bold; color: #1f2a44; vertical-align: top; {{ $loop->last ? '' : 'border-bottom: 1px solid #e4eaf2;' }}">{{ $label }}</td>
                                        <td style="padding: 8px 12px; color: #1f2a44; vertical-align: top; word-break: break-word; {{ $loop->last ? '' : 'border-bottom: 1px solid #e4eaf2;' }}">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>

                            @if (filled($remarks))
                                <p style="margin: 20px 0 4px; font-size: 14px; font-weight: bold;">{{ $remarksLabel }}</p>
                                <p style="margin: 0; font-size: 14px; line-height: 1.5; white-space: pre-line; word-break: break-word;">{{ $remarks }}</p>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 24px;">
                                <tr>
                                    <td style="background-color: #2d6fcb; border-radius: 6px;">
                                        <a href="{{ $url }}" style="display: inline-block; padding: 10px 20px; color: #ffffff; font-size: 14px; font-weight: bold; text-decoration: none;">Open the document</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 16px 0 0; font-size: 12px; color: #5b6b82; word-break: break-all;">
                                Or copy this link into your browser: {{ $url }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 24px; border-top: 1px solid #e4eaf2; font-size: 12px; line-height: 1.5; color: #5b6b82;">
                            You received this email because {{ $reason }} in the CICTO Document Tracking System.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
