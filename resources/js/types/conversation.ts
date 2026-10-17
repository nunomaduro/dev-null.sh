import type { User } from '@/types/auth';

export type Message = {
    id: number;
    user_id: string;
    client_id: string;
    body: string;
    created_at: string;
};

export type ConversationSummary = {
    id: number;
    partner: User;
    last_message: Message | null;
    last_message_at: string | null;
    ahead: number;
    partner_read_through_id: number;
    partner_read_at: string | null;
    is_archived: boolean;
};

export type Thread = {
    messages: Message[];
    has_older: boolean;
    read_through_id: number;
    partner_read_through_id: number;
    partner_read_at: string | null;
    partner_typing: boolean;
    is_archived: boolean;
    access: PushAccess;
};

/**
 * What the composer can do: push, merge a fetched request first, wait for the partner to merge, or nothing.
 */
export type PushAccess = 'open' | 'request' | 'waiting' | 'closed';

export type InboxTab = 'open' | 'fetched' | 'stashed';

export type InboxPage = {
    conversations: ConversationSummary[];
    has_more: boolean;
};

export type RemotesFrom = 'following' | 'everyone';
