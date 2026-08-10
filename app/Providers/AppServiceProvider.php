<?php

namespace App\Providers;

use App\Events\DocumentTransitioned;
use App\Listeners\DispatchDocumentNotifications;
use App\Listeners\RecordSecurityEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->assertScanBaseUrlIsSecure();
        $this->constrainNumericRouteKeys();

        // Registered explicitly rather than relying on listener auto-discovery,
        // so `route:cache`/`event:cache` and a grep both find it.
        Event::listen(DocumentTransitioned::class, DispatchDocumentNotifications::class);

        // §21 audit for non-document events. A subscriber rather than scattered
        // log calls -- framework events fire whether or not anyone remembered.
        Event::subscribe(RecordSecurityEvents::class);
    }

    /**
     * Every id-bound route key must actually look like an id.
     *
     * Without this, /documents/labels reaches the binding resolver, which
     * issues `where id = 'labels'`. SQLite and MySQL coerce that to 0 and the
     * request 404s; PostgreSQL raises 22P02 and the request 500s -- so a URL
     * typo, a stale bookmark or any crawler produces a server error in
     * production only, on the one driver that is not exercised by a quick
     * local click-through.
     */
    private function constrainNumericRouteKeys(): void
    {
        foreach (['document', 'file', 'office', 'user', 'run', 'comment', 'movement'] as $key) {
            Route::pattern($key, '[0-9]+');
        }
    }

    /**
     * The scan base URL gets printed onto paper and taped to folders. Behind a
     * shared-host reverse proxy, url() routinely emits http:// or an internal
     * hostname, and a wrong URL here is unfixable without reprinting every
     * label that has already been issued.
     *
     * Fail at boot instead, where somebody can still change it. Camera scanning
     * also needs a secure context, so http:// in production is broken twice.
     */
    protected function assertScanBaseUrlIsSecure(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        URL::forceScheme('https');

        $base = (string) config('cicto.scan_base_url');

        if (! str_starts_with($base, 'https://')) {
            throw new RuntimeException(
                'CICTO_SCAN_BASE_URL must be an https:// URL in production -- it is printed onto QR labels. '
                ."Got: {$base}",
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
            // Deliberately NOT ->uncompromised(): it calls the Have I Been
            // Pwned API and fails OPEN when the host has no outbound HTTPS,
            // which is common on LGU shared hosting. A check that silently
            // passes everything is worse than no check, because it reads as
            // protection in the §21 sign-off.
            : null,
        );
    }
}
