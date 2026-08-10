<?php

namespace App\Policies;

use App\Models\DocumentFile;
use App\Models\User;

/**
 * File access always authorizes against the PARENT document, never the file.
 *
 * The download route must use ->scopeBindings(): without it, {file} resolves
 * globally and an attacker can pair a document they can see with a file id
 * belonging to a document they cannot, and this policy would then check the
 * wrong parent.
 */
class DocumentFilePolicy
{
    public function __construct(private readonly DocumentPolicy $documents) {}

    public function view(User $user, DocumentFile $file): bool
    {
        return $this->documents->view($user, $file->document);
    }

    /**
     * Every version is downloadable by anyone who can read the document.
     *
     * Deliberately says nothing about whether the bytes still exist. A version
     * purged under the retention policy is GONE, not FORBIDDEN, and answering
     * 403 would tell a user with every right to the file that they lack
     * permission. The controller returns 410 instead.
     */
    public function download(User $user, DocumentFile $file): bool
    {
        return $this->documents->view($user, $file->document);
    }
}
