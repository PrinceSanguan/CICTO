<?php

use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\DocumentCommentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\DocumentLabelController;
use App\Http\Controllers\DocumentSignatureController;
use App\Http\Controllers\DocumentWorkflowController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

/*
| Document routes stay TOP-LEVEL for every role -- they are never nested under
| /admin or /super-admin. A document detail URL has to be identical whoever
| opens it, or the QR code would have to encode the scanner's role at print
| time and every notification link would branch on recipient role.
|
| Access is decided by DocumentPolicy, per record, not by URL prefix.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    // Literal segments before the wildcard, or /documents/scan resolves as a
    // document with the id "scan".
    Route::get('documents/scan', [ScanController::class, 'console'])->name('documents.scan');
    Route::get('documents/labels/print', [DocumentLabelController::class, 'print'])->name('documents.labels.print');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/create', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

    // Renamed from qr.svg, which was served `immutable` for a year: browsers
    // never re-request a URL cached that way, so only a new path evicts the
    // copies still carrying the old domain.
    Route::get('documents/{document}/qr-code.svg', [DocumentLabelController::class, 'svg'])->name('documents.qr');

    // §9 approve / reject / return / forward / complete
    Route::post('documents/{document}/transitions', [DocumentWorkflowController::class, 'store'])
        ->name('documents.transitions.store');

    // scopeBindings() is load-bearing: without it {file} resolves globally and
    // an attacker can pair a visible document with an invisible file id, so the
    // policy would authorise against the wrong parent document.
    Route::post('documents/{document}/files', [DocumentFileController::class, 'store'])
        ->name('documents.files.store');
    Route::get('documents/{document}/files/{file}/download', [DocumentFileController::class, 'download'])
        ->scopeBindings()
        ->name('documents.files.download');

    // Reading a version on screen rather than taking a copy away. Same
    // scopeBindings() requirement and the same reason -- and see the controller
    // for why serving an upload INLINE needs more than the download path does.
    Route::get('documents/{document}/files/{file}/preview', [DocumentFileController::class, 'preview'])
        ->scopeBindings()
        ->name('documents.files.preview');

    // §15 digital signatures.
    //
    // NO password.confirm, and that is the client's decision of 2026-09-13:
    // each office signs and passes the folder on, and being sent to a separate
    // Confirm Password screen before every signature -- which also threw the
    // drawn mark away -- stalled that. The signed-in session is the identity
    // check; SignDocument still snapshots signer, office and IP address.
    Route::post('documents/{document}/signatures', [DocumentSignatureController::class, 'store'])
        ->name('documents.signatures.store');

    /*
     * §15 undo, the client's request of 2026-09-20. DELETE because it removes
     * the attestation; DocumentSignaturePolicy::undo decides who may, and the
     * page only offers the button when it has said yes.
     */
    Route::delete('documents/{document}/signatures/{signature}', [DocumentSignatureController::class, 'destroy'])
        ->scopeBindings()
        ->name('documents.signatures.destroy');

    Route::get('documents/{document}/signatures/{signature}/certificate', [DocumentSignatureController::class, 'certificate'])
        ->scopeBindings()
        ->name('documents.signatures.certificate');

    Route::post('documents/{document}/comments', [DocumentCommentController::class, 'store'])
        ->name('documents.comments.store');
    Route::patch('documents/{document}/comments/{comment}', [DocumentCommentController::class, 'update'])
        ->scopeBindings()
        ->name('documents.comments.update');
    Route::delete('documents/{document}/comments/{comment}', [DocumentCommentController::class, 'destroy'])
        ->scopeBindings()
        ->name('documents.comments.destroy');

    // §12 notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::get('notifications/{notification}/go', [NotificationController::class, 'go'])->name('notifications.go');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
});

/*
| §7 public scan landing.
|
| Deliberately outside the auth group: a courier or citizen holding the folder
| can resolve it. The page itself decides what to reveal, based on the viewer's
| session -- possession of the token grants no authority.
|
| Throttled because the URL is printed on paper and will be crawled.
*/
Route::get('s/{token}', [ScanController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('scan.show');

/*
| §15 public signature verification, reached by scanning the QR on a printed
| Signature Certificate. Unauthenticated for the same reason as the scan path:
| the person holding the paper has to be able to check it.
|
| Reveals only what the certificate already prints, plus the verdict.
*/
/*
| §16 Archive Management. Filing, not deletion -- see ArchiveController.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('archive', [ArchiveController::class, 'index'])->name('archive.index');
    Route::post('documents/{document}/archive', [ArchiveController::class, 'store'])
        ->name('documents.archive');
    Route::delete('documents/{document}/archive', [ArchiveController::class, 'destroy'])
        ->name('documents.restore');
});

Route::get('verify/{serial}', [DocumentSignatureController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->name('signatures.verify');
