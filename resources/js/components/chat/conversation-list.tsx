import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import { ConversationActions } from '@/components/chat/conversation-actions';
import { MessageStatusGlyph } from '@/components/chat/message-status';
import { Skeleton } from '@/components/ui/skeleton';
import { UserAvatar } from '@/components/user-avatar';
import { useNow } from '@/hooks/use-now';
import { cn, formatRelativeTime, laneColor } from '@/lib/utils';
import type { ConversationSummary, User } from '@/types';

export function ConversationList({
    conversations,
    user,
    activeUsername,
    onOpen,
    onLeaveActive,
    onBlocked,
    isCompact = false,
    empty,
}: {
    conversations: ConversationSummary[] | null;
    user: User;
    activeUsername?: string | null;
    onOpen?: (partner: User) => void;
    /**
     * Called when the open conversation is archived or blocked from its row.
     */
    onLeaveActive?: () => void;
    onBlocked?: () => void;
    isCompact?: boolean;
    empty: ReactNode;
}) {
    if (conversations === null) {
        return <ConversationListSkeleton isCompact={isCompact} />;
    }

    if (conversations.length === 0) {
        return empty;
    }

    return (
        <ul aria-label="Conversations">
            {conversations.map((conversation) => (
                <ConversationRow
                    key={conversation.partner.id}
                    conversation={conversation}
                    user={user}
                    isActive={conversation.partner.username === activeUsername}
                    onOpen={onOpen}
                    onLeaveActive={onLeaveActive}
                    onBlocked={onBlocked}
                    isCompact={isCompact}
                />
            ))}
        </ul>
    );
}

function ConversationRow({
    conversation,
    user,
    isActive,
    onOpen,
    onLeaveActive,
    onBlocked,
    isCompact,
}: {
    conversation: ConversationSummary;
    user: User;
    isActive: boolean;
    onOpen?: (partner: User) => void;
    onLeaveActive?: () => void;
    onBlocked?: () => void;
    isCompact: boolean;
}) {
    const now = useNow();
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const { partner, last_message: lastMessage, ahead } = conversation;
    const isMine = lastMessage?.user_id === user.id;
    const className = cn(
        'relative grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 text-left transition-colors hover:bg-accent focus-visible:-outline-offset-2',
        isCompact ? 'px-3.5 py-2.5' : 'px-4 py-3',
        {
            'bg-primary-soft hover:bg-primary-soft': isActive,
        },
    );
    const content = (
        <>
            {isActive && (
                <span
                    aria-hidden
                    className="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-primary"
                />
            )}
            <UserAvatar
                user={partner}
                className={isCompact ? 'size-8' : 'size-10'}
            />
            <span className="min-w-0">
                <span className="flex min-w-0 items-baseline gap-1.5">
                    <span
                        className={cn(
                            'truncate font-semibold',
                            isCompact ? 'text-sm' : 'text-[15px]',
                        )}
                        title={partner.name}
                    >
                        {partner.name}
                    </span>
                    <span className="flex min-w-0 shrink-[4] items-center gap-1 font-mono text-xs text-muted-foreground">
                        <span
                            aria-hidden
                            className="size-1.5 shrink-0 rounded-full"
                            style={{ background: laneColor(partner.lane) }}
                        />
                        <span className="truncate">@{partner.username}</span>
                    </span>
                </span>
                <span
                    className={cn(
                        'flex min-w-0 items-center gap-1.5 text-muted-foreground',
                        isCompact ? 'text-[13px]' : 'text-sm',
                        { 'text-foreground': ahead > 0 },
                    )}
                >
                    {isMine && lastMessage && (
                        <MessageStatusGlyph
                            status={
                                conversation.partner_read_through_id >=
                                lastMessage.id
                                    ? 'pulled'
                                    : 'pushed'
                            }
                            lane={user.lane}
                            partnerLane={partner.lane}
                        />
                    )}
                    <span className="truncate" title={lastMessage?.body}>
                        {isMine ? 'you: ' : ''}
                        {lastMessage?.body}
                    </span>
                </span>
            </span>
            <span className="flex min-w-14 flex-col items-end transition-opacity group-focus-within:opacity-0 group-data-[menu=open]:opacity-0 [@media(hover:hover)]:group-hover:opacity-0">
                {ahead > 0 ? (
                    <span className="rounded-full bg-primary px-2 font-mono text-[11px] leading-5 font-medium whitespace-nowrap text-primary-foreground tabular-nums">
                        {ahead} ahead
                        <span className="sr-only"> unread</span>
                    </span>
                ) : (
                    conversation.last_message_at && (
                        <time
                            dateTime={conversation.last_message_at}
                            className="font-mono text-xs text-muted-foreground tabular-nums"
                        >
                            {formatRelativeTime(
                                conversation.last_message_at,
                                now,
                            )}
                        </time>
                    )
                )}
            </span>
        </>
    );

    return (
        <li
            className="group relative"
            data-menu={isMenuOpen ? 'open' : undefined}
        >
            {onOpen ? (
                <button
                    type="button"
                    onClick={() => onOpen(partner)}
                    className={className}
                >
                    {content}
                </button>
            ) : (
                <Link
                    href={ConversationController.show(partner)}
                    preserveScroll
                    aria-current={isActive ? 'page' : undefined}
                    className={className}
                >
                    {content}
                </Link>
            )}
            <div
                className={cn(
                    'absolute top-1/2 -translate-y-1/2 opacity-0 transition-opacity group-focus-within:opacity-100 group-data-[menu=open]:opacity-100 [@media(hover:hover)]:group-hover:opacity-100 [@media(hover:none)]:hidden',
                    isCompact ? 'right-2.5' : 'right-3',
                )}
            >
                <ConversationActions
                    partner={partner}
                    isArchived={conversation.is_archived}
                    onLeave={isActive ? onLeaveActive : undefined}
                    onBlocked={isActive ? onLeaveActive : onBlocked}
                    onOpenChange={setIsMenuOpen}
                />
            </div>
        </li>
    );
}

function ConversationListSkeleton({ isCompact }: { isCompact: boolean }) {
    return (
        <div
            aria-hidden
            className="motion-safe:animate-in motion-safe:delay-300 motion-safe:fade-in-0 motion-safe:fill-mode-backwards"
        >
            {Array.from({ length: 4 }, (_, index) => (
                <div
                    key={index}
                    className={cn(
                        'grid grid-cols-[auto_minmax(0,1fr)] items-center gap-3',
                        isCompact ? 'px-3.5 py-2.5' : 'px-4 py-3',
                    )}
                >
                    <Skeleton
                        className={cn(
                            'rounded-full',
                            isCompact ? 'size-8' : 'size-10',
                        )}
                    />
                    <span className="space-y-1.5">
                        <Skeleton className="h-3.5 w-32" />
                        <Skeleton className="h-3 w-48" />
                    </span>
                </div>
            ))}
        </div>
    );
}
