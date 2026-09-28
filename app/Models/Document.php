<?php

namespace App\Models;

use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\DueState;
use App\Enums\RouteStopStatus;
use App\Models\Builders\DocumentBuilder;
use App\Policies\DocumentPolicy;
use App\Support\Deadlines;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The aggregate root.
 *
 * Note what is absent: no current_office_id, no file_path, no dwell_minutes, no
 * is_overdue. All four are derivable, and all four drift.
 *
 * @property int $id
 * @property string $control_number
 * @property string $qr_token
 * @property string $title
 * @property string|null $description
 * @property string|null $remarks
 * @property int $document_type_id
 * @property int $originating_office_id
 * @property int $created_by_id
 * @property string|null $submission_group_id
 * @property DocumentStatus $status
 * @property DocumentPriority $priority
 * @property bool $is_confidential
 * @property Carbon|null $broadcast_at
 * @property int|null $broadcast_by_id
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $deadline_warned_at
 * @property Carbon|null $overdue_notified_at
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_id
 * @property string|null $archive_reason
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseEloquentBuilder(DocumentBuilder::class)]
#[UsePolicy(DocumentPolicy::class)]
#[Fillable([
    'title', 'description', 'remarks', 'document_type_id',
    'originating_office_id', 'priority',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'priority' => DocumentPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'deadline_warned_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'archived_at' => 'datetime',
            'is_confidential' => 'boolean',
            'broadcast_at' => 'datetime',
        ];
    }

    /**
     * The QR token, not the id, so a scan URL is unguessable.
     *
     * Route-model binding for the public scan path uses this explicitly via
     * {document:qr_token}; the key itself stays the id everywhere else.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    // ---------------------------------------------------------------- relations

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function originatingOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'originating_office_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function broadcastBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broadcast_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_id');
    }

    /** @return HasMany<DocumentMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(DocumentMovement::class)->orderBy('sequence');
    }

    /**
     * The single leg currently holding this document.
     *
     * Guaranteed at most one row by unique(document_id, is_open).
     *
     * @return HasOne<DocumentMovement, $this>
     */
    public function openMovement(): HasOne
    {
        return $this->hasOne(DocumentMovement::class)->whereNull('departed_at');
    }

    /**
     * The last leg the document travelled, open or closed.
     *
     * openMovement answers "who holds this now" and is correctly null once the
     * document is completed, rejected or archived. But every list in the app
     * has a Department column, and rendering an em dash there for a finished
     * document loses information that is still in the ledger -- the office it
     * finished at. This relation is what that column reads through.
     *
     * @return HasOne<DocumentMovement, $this>
     */
    public function lastMovement(): HasOne
    {
        return $this->hasOne(DocumentMovement::class)->latestOfMany('sequence');
    }

    /**
     * The routing plan: offices this document is queued to visit next.
     *
     * A PLAN, not custody. Deliberately separate from movements() -- see the
     * document_route_stops migration for why the queue is not stored in the
     * ledger. Ordered by position, resolved stops included, because the
     * document page shows the whole route and not just what is left of it.
     *
     * @return HasMany<DocumentRouteStop, $this>
     */
    public function routeStops(): HasMany
    {
        return $this->hasMany(DocumentRouteStop::class)->orderBy('position');
    }

    /** @return HasMany<DocumentFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class)->orderByDesc('version');
    }

    /**
     * Current file is MAX(version). No is_current flag -- that needs a second
     * write on every upload and is the classic source of "two current files".
     *
     * @return HasOne<DocumentFile, $this>
     */
    public function currentFile(): HasOne
    {
        return $this->hasOne(DocumentFile::class)->latestOfMany('version');
    }

    /** @return HasMany<DocumentComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class);
    }

    /** @return HasMany<DocumentSignature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class)->orderByDesc('signed_at');
    }

    /** @return HasMany<DocumentScan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(DocumentScan::class);
    }

    // ---------------------------------------------------------------- accessors

    /**
     * §11 due state, derived and never stored.
     *
     * The PHP twin of the overdue/approachingDeadline query scopes -- both read
     * their boundary from Deadlines, so a row's badge can never disagree with
     * the query that selected it.
     *
     * A plain method rather than an Eloquent accessor: Attribute<TGet, TSet> is
     * invariant, so an accessor returning a union of enum cases cannot satisfy
     * its own declared generic.
     */
    public function dueState(): DueState
    {
        return match (true) {
            $this->completed_at !== null || $this->status->isTerminal() => DueState::Closed,
            $this->due_at === null => DueState::None,
            $this->due_at->lt(Deadlines::now()) => DueState::Overdue,
            $this->due_at->lte(Deadlines::warnBoundary()) => DueState::Approaching,
            default => DueState::OnTrack,
        };
    }

    /** Which office holds it right now (§10). Derived from the open leg. */
    public function currentOffice(): ?Office
    {
        return $this->openMovement?->toOffice;
    }

    /**
     * §10 "how long it has been at the current office", in whole minutes.
     *
     * Computed in PHP: there is at most one open leg, so there is nothing to
     * aggregate, and PHP keeps Carbon::setTestNow() working. SQL duration
     * arithmetic is reserved for cross-document averages.
     */
    public function minutesAtCurrentOffice(): ?int
    {
        $leg = $this->openMovement;

        if ($leg === null || $leg->arrived_at === null) {
            return null;
        }

        return (int) $leg->arrived_at->diffInMinutes(Deadlines::now());
    }

    /**
     * Turnaround time: filed to completed, in whole minutes. Null until the
     * document is completed.
     *
     * Measured from created_at, not from the first leg's arrival, so it is the
     * same span §19's "average processing time" report averages (see
     * DocumentStats) and a single document never disagrees with the chart.
     */
    public function turnaroundMinutes(): ?int
    {
        if ($this->completed_at === null || $this->created_at === null) {
            return null;
        }

        return (int) $this->created_at->diffInMinutes($this->completed_at);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal() && $this->completed_at === null;
    }

    /**
     * Will the next receipt close this document?
     *
     * The same test AdvanceRoute::closeFinishedRoute() applies: the document
     * was given a route (any stop, whatever its status) and nothing on it is
     * still waiting. Received then completes it rather than moving it on.
     */
    public function receiptCompletes(): bool
    {
        $stops = $this->loadedRouteStops();

        return $stops->isNotEmpty()
            && ! $stops->contains(fn (DocumentRouteStop $stop) => $stop->status === RouteStopStatus::Pending);
    }

    /**
     * Is the office holding this document the LAST office on its route?
     *
     * Stricter than receiptCompletes(), and on purpose. The route has run out
     * AND the folder is sitting at the route's final visited stop -- the office
     * the plan actually ended at. An office the folder was sent to by hand,
     * off the plan, also completes it on receipt, but it is not on the route
     * at all; the client's rule of 2026-09-19 ("applicable only on the last
     * route office") does not reach it.
     *
     * Positions only ever grow (RouteDocument appends a re-route after the old
     * stops), so the highest visited position is always where the latest plan
     * ended, a round trip back to the originating office included.
     */
    public function isAtLastRouteStop(): bool
    {
        if (! $this->receiptCompletes()) {
            return false;
        }

        $last = $this->loadedRouteStops()
            ->filter(fn (DocumentRouteStop $stop) => $stop->status === RouteStopStatus::Visited)
            ->sortBy('position')
            ->last();

        $holder = $this->openMovement?->to_office_id;

        return $last !== null && $holder !== null && $holder === $last->office_id;
    }

    /**
     * Where RETURN may send this document (client request, 2026-09-25).
     *
     * It used to go to the originating office and nowhere else. Now the office
     * returning it chooses, from the offices the document has actually been at
     * -- "naka depende sa mga office na nadaanan na ng document". Every leg's
     * destination is an office that held the folder, the genesis leg's being
     * the originating office, so that column is the whole history.
     *
     * In the order the document first reached them, the originating office
     * first -- it is the default, and where a return always went before. Never
     * the office holding it now (returning to your own desk parks it in
     * `returned` with its resubmit pointing back at the same desk), and never a
     * deactivated office, which nobody could resubmit from.
     *
     * @return Collection<int, Office>
     */
    public function returnDestinations(): Collection
    {
        $legs = $this->relationLoaded('movements')
            ? $this->movements->sortBy('sequence')
            : $this->movements()->orderBy('sequence')->get(['id', 'sequence', 'to_office_id']);

        $holder = $this->openMovement?->to_office_id;

        $ids = collect([$this->originating_office_id])
            ->merge($legs->pluck('to_office_id'))
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->reject(static fn (int $id): bool => $id === $holder)
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        $offices = Office::query()
            ->whereKey($ids->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        return new Collection($ids
            ->map(static fn (int $id): ?Office => $offices->get($id))
            ->filter()
            ->values()
            ->all());
    }

    /**
     * The route stops, from the eager load when there is one -- the document
     * page asks the policy once per action, and each ask must not re-query.
     *
     * @return Collection<int, DocumentRouteStop>
     */
    private function loadedRouteStops(): Collection
    {
        return $this->relationLoaded('routeStops')
            ? $this->routeStops
            : $this->routeStops()->get();
    }
}
