import type { User } from '@/types/auth';

export type DirectMessage = {
    id: number;
    sender_id: string;
    body: string;
    created_at: string;
};

export type Conversation = {
    id: string;
    other: User;
    status: 'accepted' | 'requested' | 'declined';
    incoming_request: boolean;
    can_accept: boolean;
    can_send: boolean;
    muted: boolean;
    archived: boolean;
    unread: boolean;
    read_through: number | null;
    last_message: DirectMessage | null;
};

export type MessagePage<T> = {
    data: T[];
    next_page_url: string | null;
    prev_page_url: string | null;
};
