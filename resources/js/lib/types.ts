export type Role = 'admin' | 'multimedia' | 'communications' | 'management';

export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    status: 'active' | 'disabled';
    abilities: string[];
    last_login_at: string | null;
    /** True while the admin-generated temporary password has not been replaced yet. */
    must_change_password: boolean;
    first_login_completed: boolean;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export interface Lookups {
    projects: { id: number; name: string; slug: string; status: string }[];
    cohorts: { id: number; name: string; code: string; year: number | null; project_id: number | null }[];
    events: { id: number; title: string; slug: string; occurred_on: string | null; project_id: number | null; cohort_id: number | null }[];
    categories: { id: number; name: string; slug: string; parent_id: number | null }[];
    albums: { id: number; uuid: string; title: string; kind: AlbumKind; is_default: boolean }[];
    tags: { id: number; name: string; slug: string }[];
}

export interface UploadSessionDto {
    uuid: string;
    filename: string;
    size_bytes: number;
    mime_type: string;
    mode: 'single' | 'multipart';
    part_size: number | null;
    part_count: number;
    status: string;
    error: string | null;
    asset_uuid: string | null;
    expires_at: string;
    created_at: string;
}

export interface PresignedPut {
    url: string;
    headers: Record<string, string>;
}

export type ReviewState = 'unreviewed' | 'approved' | 'published' | 'restricted' | 'archived';

export interface AssetCard {
    uuid: string;
    kind: 'image' | 'video' | 'audio' | 'document' | 'other';
    title: string;
    mime_type: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    duration_ms: number | null;
    review_state: ReviewState;
    consent_status: string;
    storage_status: string;
    created_at: string;
    captured_at: string | null;
    thumb_url: string | null;
    original_url?: string | null;
    position?: number;
}

export interface AssetDetail extends AssetCard {
    description: string | null;
    original_filename: string;
    sha256: string;
    credit: string | null;
    project: { id: number; name: string } | null;
    event: { id: number; title: string } | null;
    cohort: { id: number; name: string } | null;
    category: { id: number; name: string } | null;
    project_id: number | null;
    event_id: number | null;
    cohort_id: number | null;
    category_id: number | null;
    tags: string[];
    albums: { uuid: string; title: string }[];
    uploader: string | null;
    reviewer: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    metadata: Record<string, unknown> | null;
    preview_url: string | null;
    poster_url: string | null;
    derivatives: { type: string; status: string; width: number; height: number; size_bytes: number }[];
    people: { id: number; full_name: string; is_minor: boolean; consent_record_id: number | null }[];
    publications: { id: number; channel: string; url: string | null; title: string | null; published_on: string; notes: string | null; recorded_by: string | null }[];
    review_history: { from: string; to: string; note: string | null; actor: string | null; at: string }[];
}

export interface AlbumSummary {
    uuid: string;
    id: number;
    title: string;
    description: string | null;
    kind: AlbumKind;
    day: string | null;
    is_default: boolean;
    is_today: boolean;
    project_id: number | null;
    event_id: number | null;
    cohort_id: number | null;
    event: string | null;
    project: string | null;
    asset_count: number;
    cover_url: string | null;
    cover_uuid: string | null;
    created_by: string | null;
    updated_at: string;
}

export type AlbumKind = 'curated' | 'daily';

export interface ShareDto {
    uuid: string;
    mode: 'public' | 'access_code' | 'restricted';
    title: string | null;
    message: string | null;
    allow_download: boolean;
    include_originals: boolean;
    expires_at: string | null;
    max_uses: number | null;
    use_count: number;
    revoked_at: string | null;
    last_accessed_at: string | null;
    created_at: string;
    created_by: string | null;
    status: 'active' | 'expired' | 'revoked' | 'used_up';
    target: { type: 'album' | 'asset'; uuid: string | null; title: string | null };
    visible_count: number;
}
