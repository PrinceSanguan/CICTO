<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Support\LoginOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The sign-in code screen (client request, 2026-09-25) -- the step between a
 * correct password and the dashboard. See RedirectToLoginOtp for how a
 * sign-in gets here and LoginOtp for the rules.
 *
 * Guest routes: nobody is signed in until verify() says so.
 */
class LoginOtpController extends Controller
{
    public function __construct(private readonly LoginOtp $otp) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $pending = $this->otp->pending($request);
        $user = $pending === null ? null : $this->otp->user($pending);

        if ($pending === null || $user === null) {
            return $this->startOver($request, 'Please sign in again.');
        }

        return Inertia::render('auth/login-otp', [
            'email' => LoginOtp::maskEmail($user->email),
            'length' => LoginOtp::LENGTH,
            'ttlMinutes' => LoginOtp::ttlMinutes(),
            'resendIn' => $this->otp->resendIn($pending),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function verify(Request $request, LoginResponse $loginResponse): SymfonyResponse
    {
        $pending = $this->otp->pending($request);
        $user = $pending === null ? null : $this->otp->user($pending);

        if ($pending === null || $user === null) {
            return $this->startOver($request, 'Your sign-in timed out. Please sign in again.');
        }

        $code = $request->validate([
            'code' => ['required', 'string', 'digits:'.LoginOtp::LENGTH],
        ], [
            'code.required' => 'Enter the '.LoginOtp::LENGTH.'-digit code from your email.',
            'code.string' => 'The code is '.LoginOtp::LENGTH.' digits.',
            'code.digits' => 'The code is exactly '.LoginOtp::LENGTH.' digits.',
        ])['code'];

        if ($this->otp->isExpired($pending)) {
            throw ValidationException::withMessages([
                'code' => 'This code has expired. Press "Send a new code" and use the newest email.',
            ]);
        }

        // Numbered before it is compared; past the limit it is not compared.
        $attempt = $this->otp->countAttempt($pending);

        if ($attempt <= LoginOtp::maxAttempts() && $this->otp->matches($pending, $code)) {
            $this->otp->forget($request);

            Auth::guard('web')->login($user, (bool) $pending['remember']);
            $request->session()->regenerate();

            return $loginResponse->toResponse($request);
        }

        $left = LoginOtp::maxAttempts() - $attempt;

        if ($left > 0) {
            throw ValidationException::withMessages([
                'code' => sprintf(
                    'That code is not correct. %d %s left.',
                    $left,
                    $left === 1 ? 'try' : 'tries',
                ),
            ]);
        }

        SecurityEvent::log(
            type: SecurityEventType::LoginOtpLockout,
            summary: sprintf('%d wrong sign-in codes for %s; the sign-in was cancelled', LoginOtp::maxAttempts(), $user->email),
            actor: $user,
            subjectLabel: $user->email,
        );

        return $this->startOver(
            $request,
            'Too many wrong codes. For your security, sign in again to get a new code.',
            asError: true,
        );
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = $this->otp->pending($request);
        $user = $pending === null ? null : $this->otp->user($pending);

        if ($pending === null || $user === null) {
            return $this->startOver($request, 'Please sign in again.');
        }

        $wait = $this->otp->resendIn($pending);

        if ($wait > 0) {
            throw ValidationException::withMessages([
                'code' => "Please wait {$wait} seconds before asking for another code.",
            ]);
        }

        $this->otp->send($request, $user);

        return redirect()->route('login.otp')->with('status', 'A new code is on its way. Use the newest email — earlier codes no longer work.');
    }

    /** "Use a different account": drop the pending sign-in. */
    public function cancel(Request $request): RedirectResponse
    {
        return $this->startOver($request, null);
    }

    private function startOver(Request $request, ?string $message, bool $asError = false): RedirectResponse
    {
        $this->otp->forget($request);

        $redirect = redirect()->route('login');

        if ($message === null) {
            return $redirect;
        }

        return $asError
            ? $redirect->withErrors(['email' => $message])
            : $redirect->with('status', $message);
    }
}
