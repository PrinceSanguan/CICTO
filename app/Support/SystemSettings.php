<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Runtime settings a Super Admin can change without a deployment.
 *
 * The pattern is always the same: a boot default in config/cicto.php, and an
 * optional row in app_settings that overrides it. The config value is what a
 * fresh installation does; the row is what this installation decided. Reading
 * it in one place stops the two rules in DocumentPolicy drifting apart, which
 * is exactly what happened when each inlined its own config() call.
 *
 * AppSetting::get uses `??`, so only a MISSING row falls through to the
 * default. A stored false is honoured as false -- which is the whole point,
 * because "the client turned self-approval off" and "the client has not chosen"
 * are different states and only one of them should defer to config.
 */
final class SystemSettings
{
    /**
     * app_settings key for the §A6 separation-of-duties switch.
     *
     * Written by SuperAdmin\SystemController::updateWorkflow, read by
     * DocumentPolicy::act and DocumentPolicy::sign.
     */
    public const ALLOW_SELF_APPROVAL = 'workflow.allow_self_approval';

    /**
     * May an Admin decide on, or sign, a document they submitted themselves?
     *
     * Client question A6. Separation of duties says no; a two-person municipal
     * office says that blocks real work, and the client asked to be able to
     * allow or block it themselves rather than have it fixed at deploy time.
     */
    public static function allowSelfApproval(): bool
    {
        $default = (bool) config('cicto.workflow.allow_self_approval', false);

        return (bool) self::read(self::ALLOW_SELF_APPROVAL, $default);
    }

    /** True once a Super Admin has actually chosen, rather than inheriting the deployed default. */
    public static function selfApprovalWasChosen(): bool
    {
        return self::read(self::ALLOW_SELF_APPROVAL, null) !== null;
    }

    /**
     * Read a setting, surviving a settings table this APP_KEY cannot read.
     *
     * setting_value is an `encrypted` cast and AppSetting::all_cached()
     * decrypts EVERY row to build its memo, so one row written under a
     * different key throws DecryptException for anyone who asks for any
     * setting. That is not hypothetical: DEPLOYMENT.md §6 warns that rotating
     * APP_KEY means re-entering every encrypted setting by hand, and §7 walks
     * an operator through restoring a dump onto another host. Either leaves
     * exactly such a row behind.
     *
     * DocumentPolicy calls this on every approval, every signature and every
     * document page (to decide whether to offer the signature pad). Letting the
     * exception through would turn one unreadable SMTP password into a 500 on
     * every document in the system -- and the operator's route to fixing it
     * runs through those same screens.
     *
     * So: report it, and answer from config instead. Falling back means falling
     * back to "self-approval blocked", which is the safe reading of a
     * separation-of-duties rule; a broken settings table must not be a way to
     * gain permission. It stays broken and loud in the log until somebody
     * re-enters the settings.
     */
    private static function read(string $key, mixed $default): mixed
    {
        try {
            return AppSetting::get($key, $default);
        } catch (DecryptException $e) {
            report($e);

            return $default;
        }
    }
}
