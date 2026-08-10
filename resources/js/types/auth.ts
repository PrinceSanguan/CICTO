export type Role = 'user' | 'admin' | 'super_admin';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    role?: Role;
    office_id?: number | null;
    position?: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type AuthOffice = {
    id: number;
    code: string;
    name: string;
};

/**
 * Coarse UI hints from Role::capabilities(). These decide what renders; they
 * never decide what is allowed. Every key has a named server-side twin in a
 * Policy or in EnsureRole, so hiding a button is a courtesy, not a control.
 */
export type Capabilities = {
    viewAnyOfficeDocuments: boolean;
    viewAllDocuments: boolean;
    approveDocuments: boolean;
    manageOfficeUsers: boolean;
    manageAllUsers: boolean;
    manageSystemSettings: boolean;
    archiveDocuments: boolean;
    viewReports: boolean;
};

export type Auth = {
    /** Null on guest pages -- the landing page and the public QR scan result. */
    user: User | null;
    role: Role | null;
    office: AuthOffice | null;
    can: Partial<Capabilities>;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
