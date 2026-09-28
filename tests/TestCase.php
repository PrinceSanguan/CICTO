<?php

namespace Tests;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\SecurityPin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * AppSetting memoises the settings table in a static, and only clears it on
     * a model event. RefreshDatabase rolls the transaction back without firing
     * one, so a row written by one test stays visible to the next test in the
     * same process -- from a table that no longer contains it. That was
     * harmless while only the mail settings read it; DocumentPolicy now reads
     * it on every approve and every signature, so a stale memo would silently
     * decide authorization for an unrelated test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        AppSetting::flushMemo();
    }

    /**
     * Sign in AND unlock the Security PIN, as a person who has just entered it.
     *
     * The PIN (2026-09-25) stands in front of every document page, so without
     * this every test that opens a document would be testing the prompt
     * instead. Tests OF the prompt start from a locked session with
     * actingAsLocked().
     */
    public function be(Authenticatable $user, $guard = null)
    {
        parent::be($user, $guard);

        if ($user instanceof User) {
            $this->withSession([SecurityPin::SESSION_KEY => [
                'user_id' => $user->id,
                'at' => now()->getTimestamp(),
            ]]);
        }

        return $this;
    }

    /** Signed in, Security PIN not yet entered in this session. */
    protected function actingAsLocked(User $user): static
    {
        parent::be($user);

        $this->app['session']->forget(SecurityPin::SESSION_KEY);

        return $this;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
