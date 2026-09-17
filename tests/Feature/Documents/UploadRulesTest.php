<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use App\Support\DocumentUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The invalid files the client's testers were given, refused in words a clerk
 * can read, at every endpoint that takes a document file.
 *
 * Real files on disk rather than UploadedFile::fake(): a fake reports the MIME
 * type of its NAME, so a .txt renamed to .pdf would sail through the content
 * check and the test would pin nothing.
 */
class UploadRulesTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'text file' => ['notes.txt', "Hello, this is a note.\n", 'type'],
            'svg image' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'type'],
            'csv' => ['list.csv', "name,office\nJuan,OCM\n", 'type'],
            'powerpoint' => ['slides.pptx', 'PK', 'type'],
            'iphone photo' => ['photo.heic', 'ftypheic', 'type'],
            'php script' => ['script.php', '<?php echo 1;', 'type'],
            'text renamed to pdf' => ['notes.pdf', "Hello, this is a note.\n", 'renamed'],
            'empty pdf' => ['empty.pdf', '', 'renamed'],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_registering_an_invalid_file_is_refused_in_plain_words(string $name, string $content, string $expected): void
    {
        Storage::fake('documents');

        $office = $this->office();

        $response = $this->actingAs($this->staff($office))
            ->post(route('documents.store'), $this->registration($office->id, $this->realUpload($name, $content)));

        $response->assertSessionHasErrors('file');
        $this->assertSame($this->expectedMessage($expected), $this->firstFileError());
        $this->assertSame(0, Document::query()->count());
    }

    public function test_a_file_over_the_limit_is_refused_with_the_limit_in_megabytes(): void
    {
        Storage::fake('documents');

        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->post(route('documents.store'), $this->registration(
                $office->id,
                UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
            ))
            ->assertSessionHasErrors('file');

        $this->assertSame('This file is too large. The limit is 10 MB.', $this->firstFileError());
    }

    public function test_the_corrected_file_and_a_new_version_are_refused_the_same_way(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $mtoAdmin = $this->admin($mto);
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();
        $versions = DocumentFile::query()->count();

        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'remarks' => 'Wrong form.',
                'file' => $this->realUpload('notes.pdf', "Hello, this is a note.\n"),
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame($this->expectedMessage('renamed'), $this->firstFileError());

        $this->actingAs($mtoAdmin)
            ->post(route('documents.files.store', $document), [
                'file' => $this->realUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame($this->expectedMessage('type'), $this->firstFileError());
        $this->assertSame($versions, DocumentFile::query()->count());
    }

    public function test_a_real_pdf_and_image_are_still_accepted(): void
    {
        Storage::fake('documents');

        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->post(route('documents.store'), $this->registration(
                $office->id,
                $this->realUpload('request.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
            ))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->staff($office))
            ->post(route('documents.store'), $this->registration(
                $office->id,
                UploadedFile::fake()->image('scan.jpg'),
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Document::query()->count());
    }

    /**
     * Every request that stores a file answers with the "Upload successful"
     * pop-up instead of a toast; a return with no file still gets the toast.
     */
    public function test_registering_confirms_with_the_success_pop_up(): void
    {
        Storage::fake('documents');

        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->post(route('documents.store'), $this->registration($office->id, UploadedFile::fake()->create('request.pdf', 40, 'application/pdf')))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('upload.fileName', 'request.pdf')
            ->assertSessionMissing('toast');

        $this->assertSame(
            'Document registered as '.Document::query()->firstOrFail()->control_number.'.',
            session('upload.message'),
        );
    }

    public function test_a_new_version_and_a_corrected_file_confirm_with_the_success_pop_up(): void
    {
        Storage::fake('documents');

        [$document, $mtoAdmin] = $this->documentAtTreasury();

        $this->actingAs($mtoAdmin)
            ->post(route('documents.files.store', $document), [
                'file' => UploadedFile::fake()->createWithContent('revised.pdf', '%PDF-1.4 REVISED'),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('upload.fileName', 'revised.pdf')
            ->assertSessionMissing('toast');

        $version = DocumentFile::query()->max('version');
        $this->assertSame("Uploaded as version {$version}.", session('upload.message'));

        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'remarks' => 'Wrong form.',
                // Different bytes: re-sending the current file is a deliberate no-op.
                'file' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 CORRECTED'),
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('upload.fileName', 'corrected.pdf');

        $this->assertStringEndsWith('Corrected file saved as version '.($version + 1).'.', (string) session('upload.message'));
    }

    public function test_a_return_without_a_file_keeps_the_toast(): void
    {
        [$document, $mtoAdmin] = $this->documentAtTreasury();

        $this->actingAs($mtoAdmin)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'returned',
                'remarks' => 'Wrong form.',
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast.type', 'success')
            ->assertSessionMissing('upload');
    }

    public function test_the_upload_confirmation_reaches_the_pages_flash_channel(): void
    {
        // The pop-up is mounted outside the page and listens on router.on('flash'),
        // so the session key has to be bridged exactly as the toast is.
        $page = $this->withSession(['upload' => ['fileName' => 'request.pdf', 'message' => 'Uploaded as version 2.']])
            ->get(route('privacy'))
            ->viewData('page');

        $this->assertSame('request.pdf', $page['flash']['upload']['fileName'] ?? null);
        $this->assertSame('Uploaded as version 2.', $page['flash']['upload']['message']);
    }

    /**
     * The pop-up refuses a wrong file before the form is sent, so the browser
     * needs the server's limits -- from config, because CICTO_UPLOAD_MAX_KB
     * differs per host.
     */
    public function test_the_browser_receives_the_same_limits_and_wording(): void
    {
        config()->set('cicto.uploads.max_size_kb', 2560);

        $office = $this->office();

        $this->actingAs($this->staff($office))
            ->get(route('documents.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('uploads.extensions', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'])
                ->where('uploads.maxKb', 2560)
                ->where('uploads.allowed', 'PDF, Word, Excel, PNG or JPG')
                ->where('uploads.messages.size', 'This file is too large. The limit is 2.5 MB.')
                ->where('uploads.messages.type', DocumentUpload::messages()['file.extensions']),
            );
    }

    /**
     * A document registered by one office and forwarded to Treasury, whose
     * admin may upload a version or return it.
     *
     * @return array{Document, User}
     */
    private function documentAtTreasury(): array
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        return [$document->refresh(), $this->admin($mto)];
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(int $officeId, UploadedFile $file): array
    {
        return [
            'title' => 'Upload probe',
            'document_type_id' => $this->documentType()->id,
            'office_ids' => [$officeId],
            'priority' => 'normal',
            'file' => $file,
        ];
    }

    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function expectedMessage(string $kind): string
    {
        return match ($kind) {
            'type' => 'This type of file cannot be uploaded. Only PDF, Word, Excel, PNG or JPG files are accepted.',
            'renamed' => 'This file is not a real PDF, Word, Excel, PNG or JPG file. It may have been renamed or be damaged.',
        };
    }

    /** The first message is the one Inertia hands the page. */
    private function firstFileError(): ?string
    {
        $errors = session('errors');

        return $errors instanceof ViewErrorBag ? $errors->getBag('default')->first('file') : null;
    }
}
