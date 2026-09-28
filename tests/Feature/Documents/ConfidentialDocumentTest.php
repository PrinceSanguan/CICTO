<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Mail\DocumentNotificationMail;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Notification;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\OfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Confidential (client, DTS_Office_Routing_Paths.pdf, 2026-09-25): "City
 * Mayor / HRMO only -- restricted, bypasses normal multi-office routing", and
 * "an access-control flag more than a workflow type".
 */
class ConfidentialDocumentTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private Office $cenro;

    private Office $hrmo;

    private Office $ocm;

    private User $filer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OfficeSeeder::class, DocumentTypeSeeder::class]);
        Storage::fake('documents');

        $this->cenro = $this->at('CENRO');
        $this->hrmo = $this->at('HRMO');
        $this->ocm = $this->at('OCM');
        $this->filer = $this->staff($this->cenro);
    }

    private function at(string $code): Office
    {
        return Office::query()->where('code', $code)->firstOrFail();
    }

    private function adminAt(Office $office): User
    {
        return User::query()->where('office_id', $office->id)->where('role', 'admin')->first()
            ?? $this->admin($office);
    }

    private function confidentialType(): DocumentType
    {
        return DocumentType::query()->where('code', 'CONFIDENTIAL')->firstOrFail();
    }

    /** @param  list<Office>  $offices */
    private function file(User $user, array $offices, string $type = 'CONFIDENTIAL'): TestResponse
    {
        return $this->actingAs($user)->post(route('documents.store'), [
            'title' => 'Complaint against a department head',
            'document_type_id' => DocumentType::query()->where('code', $type)->value('id'),
            'office_ids' => array_map(fn (Office $office) => $office->id, $offices),
            'priority' => 'normal',
            'file' => UploadedFile::fake()->create('complaint.pdf', 40, 'application/pdf'),
        ]);
    }

    private function fileToHrmo(): Document
    {
        $this->file($this->filer, [$this->cenro, $this->hrmo])->assertSessionHasNoErrors();

        return Document::query()->latest('id')->firstOrFail();
    }

    private function sees(User $user, Document $document): bool
    {
        $listed = Document::query()->readableBy($user)->whereKey($document->id)->exists();

        // The list and the lock must agree, or a row opens onto a 403.
        $this->assertSame($listed, $user->can('view', $document), "List and policy disagree for {$user->email}.");

        return $listed;
    }

    public function test_it_goes_straight_to_hrmo_when_filed_without_the_filing_office_receiving_it(): void
    {
        $document = $this->fileToHrmo();

        $this->assertTrue($document->is_confidential);
        $this->assertSame($this->cenro->id, $document->originating_office_id, 'Still registered under the filer\'s office.');
        $this->assertSame($this->hrmo->id, $document->openMovement->to_office_id, 'Already on its way to HRMO.');
        $this->assertSame(DocumentStatus::UnderReview, $document->status);
        $this->assertSame(0, $document->routeStops()->count(), 'No route: it bypasses the routing engine.');
        $this->assertSame(2, $document->movements()->count());

        // HRMO's Admin receives it; nobody at CENRO ever had to.
        $this->actingAs($this->adminAt($this->hrmo))
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_only_the_filer_and_the_office_it_went_to_can_see_it(): void
    {
        $document = $this->fileToHrmo();

        $this->assertTrue($this->sees($this->filer, $document), 'The person who filed it.');
        $this->assertTrue($this->sees($this->adminAt($this->hrmo), $document), 'HRMO, where it went.');
        $this->assertTrue($this->sees($this->staff($this->hrmo), $document), 'HRMO\'s clerks too -- the office shares one view.');

        $this->assertFalse($this->sees($this->adminAt($this->cenro), $document), 'Not the head of the office it was filed from.');
        $this->assertFalse($this->sees($this->staff($this->cenro), $document), 'Nor anybody else there.');
        $this->assertFalse($this->sees($this->adminAt($this->ocm), $document), 'Not the City Mayor\'s Office: it was never sent there.');
        $this->assertFalse($this->sees($this->superAdmin(), $document), '"City Mayor / HRMO only" -- not a Super Admin either.');
        $this->assertFalse($this->sees($this->adminAt($this->at('TREA')), $document));

        $this->actingAs($this->adminAt($this->cenro))->get(route('documents.show', $document))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('documents.show', $document))->assertForbidden();
        $this->actingAs($this->adminAt($this->hrmo))->get(route('documents.show', $document))->assertOk();

        // Its file goes with it.
        $file = $document->files()->firstOrFail();
        $this->actingAs($this->adminAt($this->cenro))
            ->get(route('documents.files.download', [$document, $file]))
            ->assertForbidden();

        // And it is not counted in the filing office's own figures.
        $this->assertSame(0, Document::query()->visibleTo($this->adminAt($this->cenro))->count());
    }

    public function test_only_people_who_can_open_it_are_told(): void
    {
        config(['mail.default' => 'smtp', 'cicto.notifications.email' => true]);
        Mail::fake();
        $this->withoutDefer();

        $cenroAdmin = $this->adminAt($this->cenro);
        $cenroAdmin->forceFill(['email_verified_at' => now()])->save();
        $hrmoAdmin = $this->adminAt($this->hrmo);
        $hrmoAdmin->forceFill(['email_verified_at' => now()])->save();

        $document = $this->fileToHrmo();

        $bell = fn (User $user) => Notification::query()->where('user_id', $user->id)->where('document_id', $document->id)->count();

        $this->assertSame(0, $bell($cenroAdmin), 'CENRO\'s Admin is not told a document they may not open was filed.');
        $this->assertSame(1, $bell($hrmoAdmin));
        $this->assertTrue(Notification::query()->where('user_id', $hrmoAdmin->id)->where('type', NotificationType::Forwarded->value)->exists());

        Mail::assertSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($hrmoAdmin->email));
        Mail::assertNotSent(DocumentNotificationMail::class, fn (DocumentNotificationMail $mail) => $mail->hasTo($cenroAdmin->email));
    }

    public function test_it_may_only_go_to_the_city_mayor_or_hrmo_and_only_one_of_them(): void
    {
        $message = 'A Confidential document goes only to Office of the City Human Resource Management Officer or Office of the City Mayor. Choose one of them.';

        $this->file($this->filer, [$this->cenro, $this->at('TREA')])->assertSessionHasErrors(['office_ids' => $message]);
        $this->file($this->filer, [$this->cenro, $this->hrmo, $this->ocm])->assertSessionHasErrors(['office_ids' => $message]);
        $this->file($this->filer, [$this->cenro, $this->ocm, $this->hrmo])->assertSessionHasErrors(['office_ids' => $message]);
        $this->file($this->filer, [$this->cenro])->assertSessionHasErrors(['office_ids' => $message]);

        $this->assertSame(0, Document::query()->count());

        // The City Mayor's Office is the other choice.
        $this->file($this->filer, [$this->cenro, $this->ocm])->assertSessionHasNoErrors();

        // Filed AT HRMO it simply stays there.
        $this->file($this->staff($this->hrmo), [$this->hrmo])->assertSessionHasNoErrors();
        $stays = Document::query()->latest('id')->firstOrFail();
        $this->assertSame($this->hrmo->id, $stays->openMovement->to_office_id);
        $this->assertSame(DocumentStatus::Initiated, $stays->status);
        $this->assertTrue($this->sees($this->adminAt($this->hrmo), $stays));
    }

    public function test_any_other_caller_is_held_to_the_same_route(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(RegisterDocument::class)->handle(
            title: 'Confidential',
            documentTypeId: $this->confidentialType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $this->cenro,
            creator: $this->filer,
            routeOfficeIds: [$this->at('TREA')->id],
        );
    }

    public function test_from_hrmo_it_can_go_on_to_the_city_mayor_and_nowhere_else(): void
    {
        $document = $this->fileToHrmo();
        $hrmoAdmin = $this->adminAt($this->hrmo);
        $send = fn (array $to) => $this->actingAs($hrmoAdmin)->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Forwarded->value,
            'to_office_ids' => $to,
            'expected_movement_id' => $document->refresh()->openMovement->id,
        ]);

        $this->actingAs($hrmoAdmin)->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Received->value,
            'expected_movement_id' => $document->openMovement->id,
        ])->assertSessionHasNoErrors();

        // The picker only offers the other trusted office.
        $this->actingAs($hrmoAdmin)->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page
                ->has('offices', 1)
                ->where('offices.0.id', $this->ocm->id));

        $send([$this->at('TREA')->id])->assertSessionHasErrors('to_office_ids');
        $send([$this->ocm->id, $this->at('TREA')->id])->assertSessionHasErrors('to_office_ids');
        $this->assertSame($this->hrmo->id, $document->refresh()->openMovement->to_office_id);

        $send([$this->ocm->id])->assertSessionHasNoErrors();
        $this->assertSame($this->ocm->id, $document->refresh()->openMovement->to_office_id);
        $this->assertTrue($this->sees($this->adminAt($this->ocm), $document), 'Now it has been at the City Mayor\'s Office.');
    }

    public function test_returned_to_the_filing_office_only_the_filer_sees_and_resubmits_it(): void
    {
        $document = $this->fileToHrmo();
        $hrmoAdmin = $this->adminAt($this->hrmo);
        $cenroAdmin = $this->adminAt($this->cenro);

        $this->actingAs($hrmoAdmin)->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Received->value,
            'expected_movement_id' => $document->openMovement->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($hrmoAdmin)->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Returned->value,
            'remarks' => 'Please attach the incident report.',
            'expected_movement_id' => $document->refresh()->openMovement->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->cenro->id, $document->refresh()->openMovement->to_office_id);

        // It is at CENRO now, and CENRO's head still cannot see it or is told.
        $this->assertFalse($this->sees($cenroAdmin, $document));
        $this->assertFalse(Notification::query()->where('user_id', $cenroAdmin->id)->where('document_id', $document->id)->exists());
        $this->assertTrue(Notification::query()->where('user_id', $this->filer->id)->where('type', NotificationType::Returned->value)->exists());

        $this->actingAs($this->filer)->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Resubmitted->value,
            'expected_movement_id' => $document->openMovement->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->hrmo->id, $document->refresh()->openMovement->to_office_id);
    }

    public function test_the_public_scan_page_does_not_show_its_title(): void
    {
        $document = $this->fileToHrmo();

        auth()->logout();

        $this->get(route('scan.show', $document->qr_token))
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/scan-public')
                ->where('document.title', 'Confidential document'));
    }

    public function test_a_super_admin_who_files_one_sees_it(): void
    {
        $super = $this->superAdmin();

        $this->file($super, [$this->cenro, $this->hrmo])->assertSessionHasNoErrors();
        $document = Document::query()->latest('id')->firstOrFail();

        $this->assertTrue($this->sees($super, $document), 'Filed it, so it is theirs to see.');
        $this->assertFalse($this->sees($this->superAdmin(), $document), 'Another Super Admin did not.');
    }

    public function test_other_types_are_not_restricted(): void
    {
        $this->file($this->filer, [$this->cenro, $this->at('TREA')], 'MEMO')->assertSessionHasNoErrors();
        $document = Document::query()->latest('id')->firstOrFail();

        $this->assertFalse($document->is_confidential);
        $this->assertTrue($this->sees($this->adminAt($this->cenro), $document));
        $this->assertTrue($this->sees($this->superAdmin(), $document));
    }
}
