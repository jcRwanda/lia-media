export type Role = 'admin' | 'multimedia' | 'communications' | 'management';

export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    status: 'active' | 'disabled';
    abilities: string[];
    last_login_at: string | null;
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
    albums: { id: number; uuid: string; title: string }[];
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
