<?php

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

    Route::get('documents/{document}/qr.svg', [DocumentLabelController::class, 'svg'])->name('documents.qr');

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

    // §15 digital signatures.
    //
    // password.confirm is the cheapest thing that makes "the method identified
    // the party" credible under RA 8792 s.8 -- a signature applied from an
    // unattended logged-in browser identifies the browser, not the person.
    Route::post('documents/{document}/signatures', [DocumentSignatureController::class, 'store'])
        ->middleware('password.confirm')
        ->name('documents.signatures.store');

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
Route::get('verify/{serial}', [DocumentSignatureController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->name('signatures.verify');
