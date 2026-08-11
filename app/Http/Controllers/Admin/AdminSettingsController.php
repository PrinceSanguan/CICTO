<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4's Settings screen.
 *
 * The design shows three groups. Only the first is a genuine preference store;
 * the other two are launch points into screens that already exist and are
 * already hardened, and they link there rather than reimplementing password
 * and two-factor flows inside the panel. Rebuilding those here would mean a
 * second, less-tested path to the two operations most worth attacking.
 *
 * Preferences are stored per user, not per installation. An LGU counter
 * terminal is shared by shift, and a date format one clerk prefers is not a
 * decision to impose on the whole office.
 */
class AdminSettingsController extends Controller
{
    /**
     * Deliberately short lists.
     *
     * Every option here has to actually work: a language the app has no
     * translations for, or a timezone the server cannot resolve, is a setting
     * that lies. English and Asia/Manila are what this deployment supports
     * today; Filipino appears when there are strings to show for it.
     *
     * @var array<string, list<array{value: string, label: string}>>
     */
    private const OPTIONS = [
        'language' => [
            ['value' => 'en', 'label' => 'English'],
        ],
        'timezone' => [
            ['value' => 'Asia/Manila', 'label' => '(GMT +8) Asia/Manila'],
        ],
        'date_format' => [
            ['value' => 'Y-m-d', 'label' => 'YYYY-MM-DD'],
            ['value' => 'd/m/Y', 'label' => 'DD/MM/YYYY'],
            ['value' => 'm/d/Y', 'label' => 'MM/DD/YYYY'],
            ['value' => 'j F Y', 'label' => '1 January 2026'],
        ],
    ];

    /** Minutes. Matches the session lifetime the app can actually enforce. */
    private const TIMEOUTS = [15, 30, 60, 120];

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/settings/index', [
            'office' => $user->office?->only(['id', 'code', 'name']),
            'options' => self::OPTIONS,
            'timeouts' => collect(self::TIMEOUTS)
                ->map(fn (int $minutes) => [
                    'value' => $minutes,
                    'label' => $minutes.' Minutes',
                ])
                ->all(),
            'settings' => [
                'language' => $user->preference('language', 'en'),
                'timezone' => $user->preference('timezone', config('app.timezone')),
                'date_format' => $user->preference('date_format', 'Y-m-d'),
                'session_timeout' => (int) $user->preference(
                    'session_timeout',
                    (int) config('session.lifetime'),
                ),
            ],
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                // Reported, never assumed: the Security screen is the only
                // place that can turn this on, and the badge here has to agree
                // with it.
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'language' => ['required', Rule::in(array_column(self::OPTIONS['language'], 'value'))],
            'timezone' => ['required', Rule::in(array_column(self::OPTIONS['timezone'], 'value'))],
            'date_format' => ['required', Rule::in(array_column(self::OPTIONS['date_format'], 'value'))],
            'session_timeout' => ['required', 'integer', Rule::in(self::TIMEOUTS)],
        ]);

        // Cast before storing. The `integer` rule accepts a numeric STRING and
        // leaves it one, so a form post stored "60" where the test's array
        // payload stored 60 -- and json_encode preserves the difference, so the
        // select would stop matching its own stored value.
        $validated['session_timeout'] = (int) $validated['session_timeout'];

        $user = $request->user();
        $user->mergePreferences($validated);
        $user->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your settings have been saved.',
        ]);
    }
}
