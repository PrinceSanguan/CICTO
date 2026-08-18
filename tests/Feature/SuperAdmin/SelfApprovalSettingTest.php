<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\DocumentStatus;
use App\Enums\SecurityEventType;
use App\Models\AppSetting;
use App\Models\SecurityEvent;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Client question A6, as the client asked for it.
 *
 * The answer they gave was not "allow it" or "block it" but "they can allow or
 * block it" -- so the decision is theirs to make and change. config/cicto.php
 * is what a fresh installation does; the app_settings row is what this
 * installation decided, and it wins.
 */
class SelfApprovalSettingTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_it_falls_back_to_the_deployed_default_until_somebody_chooses(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        $this->assertFalse(SystemSettings::allowSelfApproval());
        $this->assertFalse(SystemSettings::selfApprovalWasChosen());

        config(['cicto.workflow.allow_self_approval' => true]);

        $this->assertTrue(SystemSettings::allowSelfApproval());
        $this->assertFalse(SystemSettings::selfApprovalWasChosen());
    }

    public function test_a_stored_choice_beats_the_deployed_default_in_both_directions(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        AppSetting::put(SystemSettings::ALLOW_SELF_APPROVAL, true, 'bool', 'workflow');

        $this->assertTrue(SystemSettings::allowSelfApproval());
        $this->assertTrue(SystemSettings::selfApprovalWasChosen());

        /*
         * The direction that is easy to get wrong. AppSetting::put casts the
         * value to a string before encrypting, so false is stored as '' -- and
         * '' is not null, so AppSetting::get must NOT fall through to the
         * config default here. An LGU that deliberately turns self-approval off
         * while CICTO_ALLOW_SELF_APPROVAL=true in the environment must get off.
         */
        config(['cicto.workflow.allow_self_approval' => true]);

        AppSetting::put(SystemSettings::ALLOW_SELF_APPROVAL, false, 'bool', 'workflow');

        $this->assertFalse(SystemSettings::allowSelfApproval());
        $this->assertTrue(SystemSettings::selfApprovalWasChosen());
    }

    public function test_a_super_admin_can_turn_it_on_and_the_change_is_recorded(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.settings.workflow'), ['allow_self_approval' => true])
            ->assertRedirect();

        $this->assertTrue(SystemSettings::allowSelfApproval());

        // §21: changing who may approve their own work is exactly the kind of
        // change the security log exists for.
        $this->assertTrue(
            SecurityEvent::query()
                ->where('type', SecurityEventType::SettingChanged->value)
                ->where('summary', 'like', '%Self-approval turned ON%')
                ->exists(),
        );
    }

    public function test_an_office_admin_cannot_change_it(): void
    {
        $this->actingAs($this->admin($this->office()))
            ->post(route('super-admin.settings.workflow'), ['allow_self_approval' => true])
            ->assertForbidden();

        $this->assertNull(AppSetting::get(SystemSettings::ALLOW_SELF_APPROVAL));
    }

    public function test_the_stored_choice_actually_decides_an_approval(): void
    {
        // The point of the toggle: not that a row exists, but that the folder
        // moves. config stays on the blocking default throughout.
        config(['cicto.workflow.allow_self_approval' => false]);

        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $admin);

        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => 'received',
            'expected_movement_id' => $document->openMovement->id,
        ]);

        $document->refresh();

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'approved',
                'remarks' => 'Approving my own request',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.settings.workflow'), ['allow_self_approval' => true])
            ->assertRedirect();

        $document->refresh();

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'approved',
                'remarks' => 'Approving my own request',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    /**
     * A settings table this APP_KEY cannot read must not take the app down.
     *
     * DocumentPolicy reads app_settings on every approval, every signature and
     * every document page. AppSetting decrypts EVERY row to build its memo, so
     * one row left behind by an APP_KEY rotation or a dump restored onto
     * another host (both described in DEPLOYMENT.md) used to 500 the document
     * page -- including the screens an operator would use to fix it.
     */
    public function test_an_unreadable_settings_row_does_not_break_the_document_page(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        // Ciphertext this key cannot open, written past the encrypted cast.
        DB::table('app_settings')->insert([
            'setting_key' => 'mail.password',
            'setting_value' => 'eyJpdiI6IkJPR1VTIiwidmFsdWUiOiJCT0dVUyIsIm1hYyI6ImJvZ3VzIn0=',
            'value_type' => 'string',
            'group_name' => 'mail',
            'is_secret' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AppSetting::flushMemo();

        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $admin);

        // Falls back to the config default rather than throwing...
        $this->assertFalse(SystemSettings::allowSelfApproval());

        // ...and the page an operator needs in order to fix it still renders.
        $this->actingAs($admin)
            ->get(route('documents.show', $document))
            ->assertSuccessful();
    }

    /**
     * The fallback must fail CLOSED. A settings table nobody can read is not a
     * way to acquire a permission the config does not grant.
     */
    public function test_an_unreadable_settings_row_never_grants_self_approval(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        AppSetting::put(SystemSettings::ALLOW_SELF_APPROVAL, true, 'bool', 'workflow');

        DB::table('app_settings')
            ->where('setting_key', SystemSettings::ALLOW_SELF_APPROVAL)
            ->update(['setting_value' => 'not-a-payload']);

        AppSetting::flushMemo();

        $this->assertFalse(SystemSettings::allowSelfApproval());
    }

    public function test_re_posting_the_same_value_does_not_pad_the_security_log(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.settings.workflow'), ['allow_self_approval' => true])
            ->assertRedirect();

        $after = SecurityEvent::query()->count();

        // A double-click is not a change of policy.
        $this->actingAs($superAdmin)
            ->post(route('super-admin.settings.workflow'), ['allow_self_approval' => true])
            ->assertRedirect();

        $this->assertSame($after, SecurityEvent::query()->count());

        // A real transition still gets recorded.
        $this->actingAs($superAdmin)
            ->post(route('super-admin.settings.workflow'), ['allow_self_approval' => false])
            ->assertRedirect();

        $this->assertSame($after + 1, SecurityEvent::query()->count());
    }

    public function test_the_stored_choice_also_decides_signing(): void
    {
        // act() and sign() are separate abilities that used to re-derive the
        // same rule inline. They read one helper now, and this is what says so.
        config(['cicto.workflow.allow_self_approval' => true]);

        AppSetting::put(SystemSettings::ALLOW_SELF_APPROVAL, false, 'bool', 'workflow');

        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $admin);

        $this->assertFalse($admin->can('sign', $document->fresh()));
    }
}
