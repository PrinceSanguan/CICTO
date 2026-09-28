<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your sign-in code</title>
</head>
{{-- Same hand-written, inline-styled shell as document-notification.blade.php. --}}
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
                            <h1 style="margin: 0 0 12px; font-size: 20px; color: #1f2a44;">Your sign-in code</h1>

                            <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">Hello {{ $recipientName }},</p>
                            <p style="margin: 0 0 20px; font-size: 14px; line-height: 1.5;">Enter this code on the sign-in screen to finish signing in:</p>

                            <p style="margin: 0 0 20px; padding: 16px; background-color: #eff6fe; border-radius: 6px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #1f2a44;">{{ $displayCode }}</p>

                            <p style="margin: 0 0 20px; font-size: 14px; line-height: 1.5;">It expires in {{ $ttlMinutes }} {{ $ttlMinutes === 1 ? 'minute' : 'minutes' }} and works once. Never share it &mdash; CICTO staff will never ask you for it.</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e4eaf2; border-radius: 6px; font-size: 13px;">
                                <tr>
                                    <td style="padding: 8px 12px; width: 35%; font-weight: bold; border-bottom: 1px solid #e4eaf2;">Requested</td>
                                    <td style="padding: 8px 12px; border-bottom: 1px solid #e4eaf2;">{{ $requestedAt }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 12px; font-weight: bold; border-bottom: 1px solid #e4eaf2;">From</td>
                                    <td style="padding: 8px 12px; border-bottom: 1px solid #e4eaf2;">{{ $browser }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 12px; font-weight: bold;">IP address</td>
                                    <td style="padding: 8px 12px;">{{ $ipAddress }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 24px; border-top: 1px solid #e4eaf2; font-size: 12px; line-height: 1.5; color: #5b6b82;">
                            Did not try to sign in? Somebody has your password. Sign in and change it under Settings &gt; Security, or ask your administrator to reset it.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
