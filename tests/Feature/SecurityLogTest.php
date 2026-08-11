<?php

namespace Tests\Feature;

use App\Enums\SecurityEventType;
use App\Listeners\RecordSecurityEvents;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §21's security log has to count each event once.
 *
 * It was counting twice: Laravel's event discovery registers any public
 * `handle*` method in app/Listeners automatically, and RecordSecurityEvents is
 * ALSO registered explicitly through subscribe() -- so every sign-in wrote two
 * identical rows. A log that double-counts is worse than one that under-counts,
 * because it looks complete while every figure drawn from it is doubled.
 */
class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sign_in_is_recorded_exactly_once(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $this->assertSame(
            1,
            SecurityEvent::query()
                ->where('type', SecurityEventType::LoginSucceeded->value)
                ->count(),
            'The sign-in was recorded more than once.',
        );
    }

    public function test_a_sign_out_is_recorded_exactly_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        $this->assertSame(
            1,
            SecurityEvent::query()
                ->where('type', SecurityEventType::LoggedOut->value)
                ->count(),
        );
    }

    public function test_a_failed_sign_in_is_recorded_exactly_once(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();

        $this->assertSame(
            1,
            SecurityEvent::query()
                ->where('type', SecurityEventType::LoginFailed->value)
                ->count(),
        );
    }

    /**
     * The cause, pinned directly.
     *
     * Renaming a method back to `handle*` would reintroduce the duplication
     * without failing any behavioural test on a machine where the discovery
     * cache happens to be cold, so the naming rule itself is asserted.
     */
    public function test_no_subscriber_method_is_named_so_event_discovery_also_registers_it(): void
    {
        $subscriber = new RecordSecurityEvents;

        foreach ((new \ReflectionClass($subscriber))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === 'subscribe' || $method->getNumberOfParameters() === 0) {
                continue;
            }

            $this->assertStringStartsNotWith(
                'handle',
                $method->getName(),
                "RecordSecurityEvents::{$method->getName()}() matches Laravel's ".
                'event-discovery pattern (handle*), so it would be registered '.
                'automatically ON TOP of subscribe() and record every event twice. '.
                'Name it record* instead.',
            );
        }
    }

    /**
     * Every event the subscriber maps must exist as a method, or that event
     * silently stops being logged.
     */
    public function test_every_mapped_event_has_a_method(): void
    {
        $subscriber = new RecordSecurityEvents;
        $map = $subscriber->subscribe(app('events'));

        $this->assertNotEmpty($map);

        foreach ($map as $event => $method) {
            $this->assertTrue(
                method_exists($subscriber, $method),
                "{$event} is mapped to {$method}(), which does not exist.",
            );
        }
    }
}
