<?php

namespace App\Http\Controllers;

use App\Actions\Users\ManageSecurityPin;
use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\SecurityPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The Security PIN's own endpoints (client request, 2026-09-25).
 *
 * The gate itself is SecurityPin::isUnlocked(), asked by DocumentController::
 * show and by the RequireSecurityPin middleware. This controller is how a
 * person gets past it: create a first PIN, enter it, change or reset it with
 * their password, and let the page lock itself again when they walk away.
 */
class SecurityPinController extends Controller
{
    /**
     * A first PIN, from the "Create your Security PIN" prompt.
     *
     * Only for an account that has none. Replacing an existing PIN needs the
     * account password (update()), or anybody at an unlocked desk could simply
     * choose a new one.
     */
    public function store(Request $request, ManageSecurityPin $pins): RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasSecurityPin()) {
            throw ValidationException::withMessages([
                'pin' => 'You already have a Security PIN. Enter it, or use "Forgot PIN?" to set a new one with your password.',
            ]);
        }

        $validated = $request->validate(['pin' => SecurityPin::rules()], SecurityPin::messages());

        $pins->set($user, $validated['pin']);

        // Choosing it is proof enough for this session: they are signed in and
        // have just typed it twice.
        SecurityPin::unlock($request);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Security PIN created. You will be asked for it whenever you open a document.',
        ]);
    }

    /** Entering the PIN to open documents. */
    public function verify(Request $request, ManageSecurityPin $pins): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:'.SecurityPin::LENGTH],
        ], SecurityPin::messages());

        if (! $user->hasSecurityPin()) {
            throw ValidationException::withMessages([
                'pin' => 'You have not created a Security PIN yet.',
            ]);
        }

        // Numbered before it is checked; past the limit it is not checked at
        // all -- see SecurityPin::countAttempt().
        $attempt = SecurityPin::countAttempt($user);

        if ($attempt <= SecurityPin::maxAttempts() && $pins->check($user, $validated['pin'])) {
            SecurityPin::unlock($request);

            return back();
        }

        $left = SecurityPin::maxAttempts() - $attempt;

        if ($left > 0) {
            throw ValidationException::withMessages([
                'pin' => sprintf(
                    'That PIN is not correct. %d %s left before you are signed out.',
                    $left,
                    $left === 1 ? 'try' : 'tries',
                ),
            ]);
        }

        return $this->signOut($request, $user);
    }

    /**
     * Out of tries: end the session. Somebody working through PINs at an
     * unattended desk now faces the account password instead, and the owner
     * signs back in and carries on (or resets the PIN with that same
     * password). Logged first, while the session still says who it was.
     */
    private function signOut(Request $request, User $user): RedirectResponse
    {
        SecurityEvent::log(
            type: SecurityEventType::SecurityPinLockout,
            summary: sprintf(
                'Signed %s out after %d wrong Security PINs',
                $user->email,
                SecurityPin::maxAttempts(),
            ),
            actor: $user,
            subjectLabel: $user->email,
        );

        // The owner signing back in with their password starts with every
        // try again; the count has done its job by ending this session.
        SecurityPin::clearAttempts($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with(
            'status',
            'You entered a wrong Security PIN too many times, so you were signed out. Sign in again to continue. Forgot your PIN? After signing in, use "Forgot PIN?" to set a new one with your password.',
        );
    }

    /**
     * Change the PIN, or set a new one after forgetting it. Both need the
     * account password -- that is what makes a forgotten PIN recoverable
     * without an administrator, and what stops a passer-by from resetting it.
     */
    public function update(Request $request, ManageSecurityPin $pins): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'pin' => SecurityPin::rules(),
        ], [
            ...SecurityPin::messages(),
            'current_password.current_password' => 'That is not your password.',
        ]);

        $pins->set($user, $validated['pin']);

        SecurityPin::unlock($request);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Security PIN updated.',
        ]);
    }

    /**
     * Lock documents again -- sent by the page after the idle timeout.
     *
     * clearHistory() because an unlocked document page is kept in the
     * browser's history (encrypted, see DocumentController::show). Rotating
     * the key means pressing Back after a lock asks the server again instead
     * of redrawing the document from memory.
     */
    public function lock(Request $request): RedirectResponse
    {
        SecurityPin::lock($request);

        Inertia::clearHistory();

        return back()->with('security_pin_locked', $request->input('reason') === 'idle' ? 'idle' : 'manual');
    }

    /**
     * "Still here" from a page somebody is actively using, so an unlock does
     * not lapse under a person reading a long document without clicking
     * anything the server would see. 423 once it has lapsed, so the page knows
     * to lock itself.
     */
    public function heartbeat(Request $request): Response|JsonResponse
    {
        if (! SecurityPin::isUnlocked($request)) {
            return response()->json(['locked' => true], 423);
        }

        SecurityPin::touch($request);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
