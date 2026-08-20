<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Http\Responses\RoleAwarePasskeyLoginResponse;
use App\Support\OutgoingMail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every way a user can become authenticated lands on the right panel.
        $this->app->singleton(LoginResponseContract::class, RoleAwareLoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, RoleAwareLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RoleAwareLoginResponse::class);

        // Passkeys ship in laravel/passkeys, NOT Fortify, and carry their own
        // contract. Without this binding a passkey sign-in falls back to
        // config('passkeys.redirect') -- '/' here, since no config/passkeys.php
        // exists -- and drops the user on the public landing page.
        if (interface_exists(PasskeyLoginResponseContract::class)) {
            $this->app->singleton(PasskeyLoginResponseContract::class, RoleAwarePasskeyLoginResponse::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // §4's design ends the session on a confirmation screen rather than the
        // landing page, which looks identical whether or not sign-out worked.
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        // ONE login screen for all three roles -- see routes/web.php for what
        // replaced §3's three portals and why. It carries no role hint at all:
        // the role is read from the database row after the credentials check,
        // by RoleAwareLoginResponse. Trusting a role posted from a login form
        // would be privilege escalation with extra steps.
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        /*
         * The one auth screen that has to know whether mail works.
         *
         * Client question B3: CICTO will not supply SMTP credentials, so on
         * every deployment to date this page could not do the thing it is
         * named after. It still offered the form, and Fortify still answered
         * with a green "We have emailed your password reset link" -- a
         * statement that was false, about a token that was real and had just
         * been written into a log file.
         *
         * With `mailConfigured` false the page drops the form and says what to
         * do instead; RequireOutgoingMail refuses the POST for anyone who
         * arrives at it another way. The `status` is suppressed in the same
         * breath, because a stale green banner from an earlier request would
         * outlive the form it belonged to and contradict the warning beside it.
         */
        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => OutgoingMail::isConfigured()
                ? $request->session()->get('status')
                : null,
            'mailConfigured' => OutgoingMail::isConfigured(),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        /*
         * Registration is PUBLIC and linked from the login page, so without a
         * limiter one script can fill the users table overnight -- and because
         * User does not implement MustVerifyEmail, every one of those accounts
         * is immediately usable rather than parked awaiting a confirmation
         * nobody can send while MAIL_MAILER=log.
         */
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
