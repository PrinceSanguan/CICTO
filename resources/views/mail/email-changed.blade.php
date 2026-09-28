<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your sign-in email was changed</title>
</head>
{{-- Same hand-written, inline-styled shell as login-otp.blade.php. --}}
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
                            <h1 style="margin: 0 0 12px; font-size: 20px; color: #1f2a44;">Your sign-in email was changed</h1>

                            <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">Hello {{ $name }},</p>
                            <p style="margin: 0 0 12px; font-size: 14px; line-height: 1.5;">The email address on your account was changed to:</p>

                            <p style="margin: 0 0 20px; padding: 12px 16px; background-color: #eff6fe; border-radius: 6px; font-size: 16px; font-weight: bold; color: #1f2a44;">{{ $maskedAddress }}</p>

                            <p style="margin: 0 0 20px; font-size: 14px; line-height: 1.5;">Sign-in codes and notifications now go there, not to this address.</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e4eaf2; border-radius: 6px; font-size: 13px;">
                                <tr>
                                    <td style="padding: 8px 12px; width: 35%; font-weight: bold; border-bottom: 1px solid #e4eaf2;">Changed</td>
                                    <td style="padding: 8px 12px; border-bottom: 1px solid #e4eaf2;">{{ $changedAt }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 12px; font-weight: bold;">IP address</td>
                                    <td style="padding: 8px 12px;">{{ $fromIp }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 24px; border-top: 1px solid #e4eaf2; font-size: 12px; line-height: 1.5; color: #5b6b82;">
                            Did not do this? Somebody may be using your account. Tell the CICTO office straight away so an administrator can move it back.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
