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
    due_at: string | null;
    due_state: string;
    due_state_label: string;
    due_state_tone: Tone;
    is_archived: boolean;
    created_at: string | null;
};

/** Spec §10: current stage, holding office, time there, expected completion. */
export type DocumentTracking = {
    current_office: string | null;
    current_office_id: number | null;
    arrived_at: string | null;
    minutes_at_current_office: number | null;
    time_at_current_office: string | null;
    leg_due_at: string | null;
    expected_completion_at: string | null;
};

export type DocumentAction = {
    value: string;
    label: string;
    requires_remarks: boolean;
};

export type DocumentDetail = DocumentListItem & {
    description: string | null;
    remarks: string | null;
    originating_office: string | null;
    created_by: string | null;
    completed_at: string | null;
    tracking: DocumentTracking;
    available_actions: DocumentAction[];
    /** The open leg the page was rendered from -- posted back to defeat double-submits. */
    expected_movement_id: number | null;
    can: {
        update: boolean;
        uploadVersion: boolean;
        comment: boolean;
        sign: boolean;
        archive: boolean;
        restore: boolean;
    };
};

/** Spec §13 audit trail. */
export type TimelineEntry = {
    id: number;
    sequence: number;
    action: string;
    action_label: string;
    verb: string;
    actor: string | null;
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
    signer_name: string;
    signer_position: string | null;
    signer_office: string | null;
    purpose: string;
    method: string;
    file_version: number | null;
    signed_at: string;
    valid: boolean;
    superseded: boolean;
};
