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
