<?php

namespace App\Models\Builders;

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\Document;
use App\Models\User;
use App\Support\Deadlines;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @extends Builder<Document>
 */
class DocumentBuilder extends Builder
{
    /**
     * Row-level office scoping (§2).
     *
     * The predicate is an EXISTS against document_movements, never a column on
     * documents: documents MOVE, and an office must keep seeing what it has
     * already handled. A denormalised current_office_id would show an office
     * only what it holds right now, which is the opposite of a tracking system.
     *
     * This is a local scope rather than a global one on purpose. A global scope
     * has to silently no-op for console, queue and guest contexts -- and the QR
     * scan path is a guest context.
     */
    public function visibleTo(?User $user): self
    {
        if (! $user instanceof User) {
            return $this->whereRaw('1 = 0');
        }

        if ($user->role === Role::SuperAdmin) {
            return $this;
        }

        if ($user->role === Role::Admin && $user->office_id !== null) {
            $officeId = $user->office_id;

            return $this->where(function (self $query) use ($officeId, $user): void {
                $query
                    ->whereExists(function (QueryBuilder $sub) use ($officeId): void {
                        $sub->selectRaw('1')
                            ->from('document_movements')
                            ->whereColumn('document_movements.document_id', 'documents.id')
                            ->where(function (QueryBuilder $where) use ($officeId): void {
                                $where->where('document_movements.to_office_id', $officeId)
                                    ->orWhere('document_movements.from_office_id', $officeId);
                            });
                    })
                    ->orWhere('documents.originating_office_id', $officeId)
                    ->orWhere('documents.created_by_id', $user->id);
            });
        }

        // Plain users, and admins who have not been assigned an office yet, see
        // only what they submitted.
        return $this->where('documents.created_by_id', $user->id);
    }

    /** Documents an office is holding right now -- the open leg points at it. */
    public function heldByOffice(int $officeId): self
    {
        return $this->whereExists(function (QueryBuilder $sub) use ($officeId): void {
            $sub->selectRaw('1')
                ->from('document_movements')
                ->whereColumn('document_movements.document_id', 'documents.id')
                ->whereNull('document_movements.departed_at')
                ->where('document_movements.to_office_id', $officeId);
        });
    }

    /**
     * Excludes archived documents. Applied to LIST views only -- never as a
     * global scope, or archived work silently vanishes from every report total
     * and the dashboard undercounts completed documents.
     */
    public function active(): self
    {
        return $this->whereNull('documents.archived_at');
    }

    public function archived(): self
    {
        return $this->whereNotNull('documents.archived_at');
    }

    /** Not yet finished: the deadline clock is still running. */
    public function stillOpen(): self
    {
        return $this->whereNull('documents.completed_at')
            ->whereNotIn('documents.status', DocumentStatus::terminalValues());
    }

    /**
     * §11 overdue. A query predicate, never a stored flag column -- so it stays
     * correct on a host with no cron.
     *
     * now() is bound from PHP, never SQL NOW(): with APP_TIMEZONE set, the PHP
     * clock and the database server clock are different clocks, and mixing them
     * makes every elapsed-time figure quietly wrong. It also keeps
     * Carbon::setTestNow() working.
     */
    public function overdue(): self
    {
        return $this->stillOpen()
            ->whereNotNull('documents.due_at')
            ->where('documents.due_at', '<', Deadlines::now());
    }

    /** §11 approaching deadline: inside the warning window but not yet past due. */
    public function approachingDeadline(): self
    {
        return $this->stillOpen()
            ->whereNotNull('documents.due_at')
            ->where('documents.due_at', '>=', Deadlines::now())
            ->where('documents.due_at', '<=', Deadlines::warnBoundary());
    }

    /**
     * §8 search. Both sides lowercased because MySQL LIKE is case-insensitive
     * and PostgreSQL LIKE is not -- the single most common way this codebase
     * could behave differently per driver.
     *
     * LIKE metacharacters are escaped so a clerk typing "100%" does not match
     * every row.
     */
    public function search(?string $term): self
    {
        $term = is_string($term) ? trim($term) : null;

        if ($term === null || $term === '') {
            return $this;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($term)).'%';

        return $this->where(function (self $query) use ($like): void {
            $query->whereRaw('lower(documents.control_number) like ?', [$like])
                ->orWhereRaw('lower(documents.title) like ?', [$like]);
        });
    }
}
