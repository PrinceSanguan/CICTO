export type Tone = 'slate' | 'amber' | 'sky' | 'orange' | 'red' | 'emerald';

export type DocumentListItem = {
    id: number;
    control_number: string;
    title: string;
    status: string;
    status_label: string;
    status_tone: Tone;
    priority: string;
    priority_label: string;
    priority_tone: Tone;
    document_type: string | null;
    current_office: string | null;
    resting_office: string;
    due_at: string | null;
    due_state: string;
    due_state_label: string;
    due_state_tone: Tone;
    is_archived: boolean;
    /** Seen only by its filer and the City Mayor's or HRMO's people. */
    is_confidential: boolean;
    /** Sent to every office, which may all read it. */
    is_broadcast: boolean;
    created_at: string | null;
};

/** Spec §10: current stage, holding office, time there, expected completion. */
export type DocumentTracking = {
    current_office: string | null;
    /** Where it is, or -- once finished -- where it finished. Never null. */
    resting_office: string;
    /** False once the document is completed, rejected or archived. */
    is_open: boolean;
    current_office_id: number | null;
    arrived_at: string | null;
    minutes_at_current_office: number | null;
    time_at_current_office: string | null;
    leg_due_at: string | null;
    expected_completion_at: string | null;
    /** Filed to completed. Null until the document is completed. */
    turnaround_minutes: number | null;
    turnaround: string | null;
};

export type DocumentAction = {
    value: string;
    label: string;
    requires_remarks: boolean;
};

/** One office on a document's routing plan. Mirrors App\Enums\RouteStopStatus. */
export type RouteStop = {
    id: number;
    position: number;
    office: string | null;
    status: 'pending' | 'visited' | 'cancelled';
    status_label: string;
    status_tone: Tone;
};

/**
 * Where a document's route started: its originating office.
 *
 * Not a RouteStop, because it is not a stop -- it has no row in
 * document_route_stops and nothing queues it. The §5 submit form picks the
 * departments as one ordered list and registers the document under the first
 * of them, so without this the Route panel drew every route one office short.
 */
export type RouteOrigin = {
    office: string | null;
    status_label: string;
    status_tone: Tone;
};

/** Mirrors DocumentPresenter::returnNotice. */
export type ReturnNotice = {
    returned_by: string | null;
    /** The office that returned it, and where Resubmit sends it back to. */
    returned_by_office: string | null;
    /** Where it was returned TO -- the returning office's choice since 2026-09-25. */
    returned_to_office: string | null;
    returned_at: string | null;
    remarks: string | null;
};

/** Mirrors DocumentPresenter::returnOptions: an office Return may send it to. */
export type ReturnOption = {
    id: number;
    name: string;
    is_originating: boolean;
    /** Whether anybody at that office could resubmit it. */
    has_staff: boolean;
};

/** One of the other documents produced by a single simultaneous submit. */
export type SubmissionSibling = {
    id: number;
    control_number: string;
    office: string | null;
    status_label: string;
    status_tone: Tone;
};

export type DocumentDetail = DocumentListItem & {
    description: string | null;
    remarks: string | null;
    originating_office: string | null;
    created_by: string | null;
    completed_at: string | null;
    tracking: DocumentTracking;
    /**
     * §9's routing plan, in visiting order. Empty for a document sent one
     * office at a time.
     *
     * A sibling of `tracking`, not part of it: `tracking` says where the folder
     * IS, which is always one office, and this says where it is GOING.
     */
    route: RouteStop[];
    /**
     * The office the route started from. Rendered above `route` as its first
     * step, so the panel names every department the submitter picked.
     */
    route_origin: RouteOrigin | null;
    /**
     * Why a returned document was sent back, and to which office. Null unless
     * the document is `returned`. Resubmit sends it to `returned_by_office`.
     */
    return_notice: ReturnNotice | null;
    /**
     * The offices Return may send it to: the ones it has already been at,
     * originating office first (2026-09-25). Empty when Return is impossible.
     */
    return_options: ReturnOption[];
    /**
     * The other documents the same submit produced, when it was sent to several
     * departments at the same time. Empty for every other document.
     *
     * Separate documents, not stops: a flat submit has no route, and a routed
     * document has no siblings.
     */
    submitted_with: SubmissionSibling[];
    available_actions: DocumentAction[];
    /** Pressing Received closes the document: its route has run out. */
    receipt_completes: boolean;
    /** The open leg the page was rendered from -- posted back to defeat double-submits. */
    expected_movement_id: number | null;
    /**
     * §15 handoff. Present when the office currently holding this document has
     * already signed the exact version it is holding; null when it has not, or
     * when nobody holds it any more.
     */
    release_signature: ReleaseSignature | null;
    /** When it was sent to every office, and by whom; null until then. */
    broadcast: { at: string; by: string | null; office: string | null } | null;
    /** Whether this document's type is one that is sent to every office. */
    allows_broadcast: boolean;
    /** Open to this viewer only because it was broadcast: read, nothing else. */
    read_only_broadcast: boolean;
    can: {
        update: boolean;
        uploadVersion: boolean;
        comment: boolean;
        sign: boolean;
        signRelease: boolean;
        archive: boolean;
        restore: boolean;
        broadcast: boolean;
    };
};

/** Mirrors DocumentPresenter::releaseSignature. */
export type ReleaseSignature = {
    serial: string;
    signer_name: string;
    signer_position: string | null;
    signed_at: string;
    file_version: number | null;
};

/** Spec §13 audit trail. */
export type TimelineEntry = {
    id: number;
    sequence: number;
    action: string;
    action_label: string;
    verb: string;
    actor: string | null;
    /** Office bracketed beside the actor; null when none applies. */
    actor_office: string | null;
    from_office: string | null;
    to_office: string | null;
    remarks: string | null;
    arrived_at: string | null;
    departed_at: string | null;
    is_open: boolean;
    dwell_minutes: number | null;
    dwell: string | null;
};

export type DocumentFileItem = {
    id: number;
    version: number;
    original_name: string;
    size: string;
    mime_type: string;
    uploaded_by: string | null;
    uploaded_at: string | null;
    replace_reason: string | null;
    is_purged: boolean;
    /** Whether the browser can render this one. See DocumentFile::PREVIEWABLE. */
    is_previewable: boolean;
};

export type DocumentCommentItem = {
    id: number;
    body: string;
    context: string;
    is_internal: boolean;
    author: string | null;
    created_at: string | null;
    edited_at: string | null;
    can_edit: boolean;
    can_delete: boolean;
};

export type SelectOption = {
    value: string;
    label: string;
};

export type IdNameOption = {
    id: number;
    name: string;
    code?: string;
    turnaround_days?: number | null;
    /**
     * Is there anybody at this office who can take a document in?
     *
     * False means the office has no active Admin account, so a document sent
     * there arrives and cannot be received by anyone -- see Office::withReceiver.
     * OPTIONAL, and undefined is not false: only the payloads that ship the flag
     * can warn about it, and a caller that does not must not imply every office
     * is unstaffed.
     */
    can_receive?: boolean;
};

/**
 * One step of a document type's suggested route, as saved on the Document
 * Types page -- App\Support\RouteTemplates::forClient().
 *
 *  - `office`: a fixed office. `office_id` is null when this installation has
 *    no such active office, and `missing_office` then names it.
 *  - `choose`: the sender picks. `suggested` is an office id, `'origin'` for
 *    the office filing it, or null; `only` limits the choice (null: any).
 *  - `same`: whatever step `step` (0-based) ended up as.
 *  - `note`: something the system does not do, said where it happens.
 *
 * `purpose` is what happens at that step; empty when the client's list names
 * only the office.
 */
export type RouteTemplateStep =
    | {
          kind: 'office';
          office_id: number | null;
          missing_office: string | null;
          purpose: string;
          optional: boolean;
          /** An optional step that starts ticked (the BAC's members). */
          checked: boolean;
      }
    | {
          kind: 'choose';
          purpose: string;
          optional: boolean;
          suggested: number | 'origin' | null;
          only: number[] | null;
      }
    | { kind: 'same'; step: number; purpose: string }
    | { kind: 'note'; purpose: string };

export type RouteTemplate = {
    note: string | null;
    /** City Mayor / HRMO only: the route cannot be edited by hand. */
    confidential: boolean;
    /** The type is sent to every office from the document page. */
    broadcast: boolean;
    steps: RouteTemplateStep[];
};

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type DocumentFilters = {
    q?: string;
    status?: string;
    priority?: string;
    office_id?: number | string;
    document_type_id?: number | string;
    from?: string;
    to?: string;
    due?: string;
    sort?: string;
    dir?: string;
    per_page?: number | string;
};

/** Spec §13: how long the document stayed at each office. */
export type OfficeDwell = {
    office: string;
    visits: number;
    minutes: number;
    duration: string | null;
    is_current: boolean;
};

/** Spec §15. `valid` and `superseded` are computed per request, never stored. */
export type SignatureItem = {
    id: number;
    serial: string;

    /**
     * §15 undo. Answered by DocumentSignaturePolicy, never guessed at here:
     * it depends on who holds the folder and what has happened since.
     */
    can_undo: boolean;
    signer_name: string;
    signer_position: string | null;
    signer_office: string | null;
    purpose: string;
    purpose_label: string;
    method: string;
    file_version: number | null;
    signed_at: string;
    valid: boolean;
    superseded: boolean;
};

/** Shared on every page from App\Support\DocumentUpload::forClient(). */
export type UploadRules = {
    extensions: string[];
    maxKb: number;
    /** "PDF, Word, Excel, PNG or JPG" */
    allowed: string;
    messages: {
        type: string;
        size: string;
        empty: string;
        incomplete: string;
    };
};
