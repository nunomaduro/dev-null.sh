import type { User } from '@/types/auth';

export type CommitStatus = 'pending' | 'approved' | 'rejected';

export type EmbedKind = 'video' | 'audio' | 'code';

export type Embed = {
    provider: string;
    kind: EmbedKind;
    src: string;
    aspect_ratio: string | null;
    height: number | null;
};

export type LinkPreview = {
    url: string;
    title: string | null;
    description: string | null;
    image_url: string | null;
    site_name: string | null;
    is_pending: boolean;
    embed?: Embed | null;
};

export type PollOption = {
    id: string;
    label: string;
    votes_count: number | null;
    voted: boolean;
};

export type Poll = {
    id: string;
    closes_at: string | null;
    is_closed: boolean;
    has_voted: boolean;
    total_votes: number | null;
    options: PollOption[];
};

export type CommitImage = {
    id: string;
    url: string;
    width: number;
    height: number;
    description: string | null;
};

export type CommitGif = {
    id: string;
    url: string;
    page_url: string;
    title: string | null;
    username: string | null;
    width: number | null;
    height: number | null;
};

export type BodySegment =
    | { type: 'text'; text: string }
    | { type: 'code'; code: string }
    | { type: 'block'; language: string | null; html: string };

export type Commit = {
    id: string;
    user_id: string;
    parent_id: string | null;
    hot_score: number;
    body: string;
    body_segments: BodySegment[];
    preview_segments: BodySegment[] | null;
    status: CommitStatus;
    created_at: string;
    updated_at: string;
    deleted_at?: null;
    stars_count: number;
    pasteis_count: number;
    starred: boolean;
    user: User;
    replies_count?: number;
    reply_preview?: CursorPaginated<ThreadCommit>;
    is_saved?: boolean;
    saved_order?: number | null;
    is_pinned?: boolean;
    parent?: (Omit<Commit, 'parent'> & { user: User }) | DeletedCommit | null;
    link_preview?: LinkPreview | null;
    poll?: Poll | null;
    quoted_commit_id: string | null;
    quoted_commit?: QuotedCommit | DeletedCommit | null;
    images?: CommitImage[];
    gif?: CommitGif | null;
    ai_decision?: Exclude<CommitStatus, 'pending'> | null;
    ai_categories?: string[] | null;
};

export type QuotedCommit = Pick<
    Commit,
    | 'id'
    | 'user_id'
    | 'body'
    | 'body_segments'
    | 'preview_segments'
    | 'status'
    | 'created_at'
    | 'deleted_at'
    | 'user'
    | 'replies_count'
    | 'poll'
    | 'link_preview'
    | 'images'
>;

export type DeletedCommit = {
    id: string;
    parent_id: string | null;
    deleted_at: string;
    replies_count?: number;
};

export type ThreadCommit = Commit | DeletedCommit;

export type CursorPaginated<T> = {
    data: T[];
    next_cursor: string | null;
    prev_cursor: string | null;
};
