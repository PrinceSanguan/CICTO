<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\DocumentMovement;
use App\Models\DocumentSignature;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Close the user's own account.
     *
     * DEACTIVATES rather than deletes whenever the account has touched a
     * municipal record, and that is the whole point of this method.
     *
     * `document_movements.actor_id` is nullOnDelete, so a real delete silently
     * rewrites the audit trail: every "Forwarded by Maria Santos" in the ledger
     * becomes "Forwarded by nobody", retroactively, with no record that it ever
     * said anything else. §13 exists to prevent exactly that. Meanwhile
     * `documents.created_by_id` and `document_signatures.user_id` are
     * restrictOnDelete, so for anyone who has registered or signed a document
     * the delete throws a QueryException -- after Auth::logout() has already
     * run, leaving them signed out with an unexplained 500.
     *
     * Deactivation keeps the history readable and honest, and
     * EnsureAccountIsActive logs the account out of every session on its next
     * request. A genuinely empty account -- a mistaken signup -- is still
     * deleted outright, because there is nothing to preserve.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        $hasHistory = $user->submittedDocuments()->exists()
            || DocumentMovement::query()->where('actor_id', $user->id)->exists()
            || DocumentSignature::query()->where('user_id', $user->id)->exists();

        if ($hasHistory) {
            // Deactivate first, then sign out: nothing here can fail on a
            // foreign key, and doing the work while still authenticated means a
            // failure leaves the user looking at an error rather than signed
            // out and looking at a 500.
            $user->forceFill(['is_active' => false])->save();
            Auth::logout();
        } else {
            // Sign out FIRST for a real delete. Logging out fires
            // Illuminate\Auth\Events\Logout, which RecordSecurityEvents turns
            // into a security_events row referencing this user id. Run after the
            // delete, that insert violates the foreign key -- and the failed
            // statement rolls back the enclosing savepoint, quietly resurrecting
            // the account the user asked to remove.
            Auth::logout();
            $user->delete();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('toast', [
            'type' => 'success',
            'message' => $hasHistory
                ? 'Your account has been closed. Your name stays on the documents you handled, as the records require.'
                : 'Your account has been deleted.',
        ]);
    }
}
