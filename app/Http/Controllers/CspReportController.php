<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where the report-only CSP sends its violations.
 *
 * The policy ships non-enforcing so the deployment can be watched before it
 * starts blocking things. That only works if the reports go somewhere a person
 * can read, which is the `csp` log channel.
 *
 * Unauthenticated and CSRF-exempt by necessity: the browser posts these itself,
 * with no session and no token. It is therefore rate limited and the stored
 * payload is truncated -- this endpoint accepts input from anyone on the
 * internet and must not become a way to fill the disk.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->json('csp-report', $request->json()->all());

        if (is_array($payload)) {
            $payload = array_intersect_key($payload, array_flip([
                'document-uri',
                'violated-directive',
                'effective-directive',
                'blocked-uri',
                'line-number',
                'source-file',
            ]));
        }

        Log::channel('csp')->warning('CSP violation', [
            'report' => mb_substr(json_encode($payload) ?: '', 0, 2000),
            'ua' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // 204: the browser does not read the body and should not retry.
        return response()->noContent();
    }
}
