<?php

namespace Tests;

use App\Models\AppSetting;
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

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
