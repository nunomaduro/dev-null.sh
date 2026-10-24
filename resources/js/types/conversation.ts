import type { User } from '@/types/auth';
import type { BodySegment, LinkPreview } from '@/types/commit';

/**
 * The message a reply answers, as much as its ghost shows.
 */
export type MessageReply = {
    id: number;
    user_id: string;
    body: string;
    images_count: number;
    has_voice: boolean;
};

export type MessageImage = {
    id: string;
    url: string;
    width: number;
    height: number;
    description: string | null;
};

/**
 * A voice note's waveform runs from 0 to 100 per bar. The audio itself is only fetched when it is played.
 */
export type MessageVoice = {
    id: string;
    url: string;
    duration_ms: number;
    waveform: number[];
};

export type MessageReaction = {
    user_id: string;
    emoji: string;
};

/**
 * The reactions of a message that changed since the version a poll last saw.
 */
export type MessageReactionChange = {
    id: number;
    reactions: MessageReaction[];
};

export type Message = {
    id: number;
    user_id: string;
    client_id: string;
    body: string;
    body_segments?: BodySegment[];
    reply_to_id?: number | null;
    reply_to?: MessageReply | null;
    mentions?: Record<string, string> | null;
    link_preview?: LinkPreview | null;
    images?: MessageImage[];
    voice?: MessageVoice | null;
    reactions?: MessageReaction[];
    event?: OrgEvent | null;
    subject_id?: string | null;
    user?: User;
    subject?: User | null;
    pinned_at?: string | null;
    created_at: string;
};

export type ConversationKind = 'direct' | 'org';

export type OrgRole = 'maintainer' | 'contributor';

/**
 * What happened in an org, logged between its messages.
 */
export type OrgEvent =
    | 'created'
    | 'added'
    | 'removed'
    | 'left'
    | 'renamed'
    | 'promoted'
    | 'demoted'
    | 'inherited';

export type OrgRef = { id: string; name: string | null };

export type OrgMember = {
    suggests_mentions: boolean;
    is_followed: boolean;
    user: User;
    role: OrgRole | null;
    read_through_id: number;
};

export type ConversationSummary = {
    id: string;
    kind: ConversationKind;
    name: string | null;
    partner: User | null;
    members: { user: User; role: OrgRole | null }[] | null;
    last_message: Message | null;
    last_incoming_message_id: number | null;
    last_incoming_sender: string | null;
    last_message_at: string | null;
    ahead: number;
    partner_read_through_id: number;
    partner_read_at: string | null;
    is_archived: boolean;
    pinned_at: string | null;
    muted_until_at: string | null;
};

export type Thread = {
    messages: Message[];
    has_older: boolean;
    reactions_version: number;
    reaction_changes: MessageReactionChange[];
    read_through_id: number;
    partner_read_through_id: number;
    partner_read_at: string | null;
    partner_typing: boolean;
    is_archived: boolean;
    pinned_at: string | null;
    muted_until_at: string | null;
    access: PushAccess;
};

export type OrgThread = {
    messages: Message[];
    has_older: boolean;
    reactions_version: number;
    reaction_changes: MessageReactionChange[];
    read_through_id: number;
    members: OrgMember[];
    typing: string[];
    is_archived: boolean;
    pinned_at: string | null;
    muted_until_at: string | null;
    role: OrgRole | null;
    access: PushAccess;
    /**
     * The org's pinned messages, latest pinned first. Null when they haven't changed since the reactions version the
     * poll asked after, so the ones already shown stay.
     */
    pinned: Message[] | null;
};

/**
 * What the composer can do: push, merge a fetched request first, wait for the partner to merge, or nothing, either
 * because the partner doesn't follow the user while requests are off, or has remotes off.
 */
export type PushAccess =
    | 'open'
    | 'request'
    | 'waiting'
    | 'restricted'
    | 'closed';

export type InboxTab = 'open' | 'fetched' | 'stashed';

export type InboxPage = {
    conversations: ConversationSummary[];
    has_more: boolean;
};

export type RemotesFrom = 'following' | 'everyone';
