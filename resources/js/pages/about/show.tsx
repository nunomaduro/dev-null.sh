import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    Flag,
    MessageSquare,
    Newspaper,
    Star,
    User,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    DocumentList,
    DocumentPage,
    DocumentSection,
} from '@/components/document-page';
import { UserPreview } from '@/components/user-preview';
import { WordmarkText } from '@/components/wordmark-text';
import AppLayout from '@/layouts/app-layout';
import type {
    StoryCommit,
    StoryContributor,
    StoryEvent,
    StoryEventKind,
    StoryStream,
} from '@/lib/about';
import {
    storyAfter,
    storyAfterLane,
    storyBefore,
    storyFounders,
    storyStreams,
} from '@/lib/about';
import { cn, laneColor } from '@/lib/utils';
import { show } from '@/routes/user';
import type { Lane } from '@/types';

const kindIcons: Record<StoryEventKind, typeof User> = {
    person: User,
    milestone: Flag,
    press: Newspaper,
};

const kindLabels: Record<StoryEventKind, string> = {
    person: 'joined',
    milestone: 'milestone',
    press: 'press',
};

function ExternalLink({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <a href={href} target="_blank" rel="noopener noreferrer">
            {children}
        </a>
    );
}

function EventTitle({ event }: { event: StoryEvent }) {
    const face = event.commit?.author ?? event.person;

    if (face) {
        return <Link href={`/@${face.login}`}>{event.title}</Link>;
    }

    if (event.href === undefined) {
        return <>{event.title}</>;
    }

    return (
        <a
            href={event.href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-0.5"
        >
            {event.title}
            <ArrowUpRight aria-hidden className="size-3.5 shrink-0" />
        </a>
    );
}

/**
 * What someone said when they joined, drawn like a commit in the log.
 */
function JoinCommit({ commit }: { commit: StoryCommit }) {
    const { author } = commit;

    return (
        <Link
            href={`/@${author.login}/commits/${commit.id}`}
            className="mt-2 block rounded-lg border bg-background px-3.5 py-3 no-underline! transition-colors hover:bg-muted"
        >
            <span className="flex items-center gap-2.5">
                <img
                    src={author.avatar}
                    alt=""
                    width={36}
                    height={36}
                    loading="lazy"
                    className="size-9 shrink-0 rounded-full bg-muted"
                />
                <span className="min-w-0 leading-tight">
                    <span className="block truncate font-medium">
                        {author.name}
                    </span>
                    <span className="block truncate text-sm text-muted-foreground">
                        @{author.login}
                    </span>
                </span>
                <span className="ml-auto font-mono text-xs text-muted-foreground">
                    {commit.id.slice(-7)}
                </span>
            </span>
            <span className="mt-2 block whitespace-pre-line text-foreground">
                {commit.body}
            </span>
            <span className="mt-2 flex gap-4 font-mono text-xs text-muted-foreground tabular-nums">
                <span className="inline-flex items-center gap-1">
                    <Star aria-hidden className="size-3.5" />
                    {commit.stars}
                    <span className="sr-only">stars</span>
                </span>
                <span className="inline-flex items-center gap-1">
                    <MessageSquare aria-hidden className="size-3.5" />
                    {commit.replies}
                    <span className="sr-only">replies</span>
                </span>
            </span>
        </Link>
    );
}

/**
 * A stretch of the story, drawn as a lane: a line down the left with a dot for every event on it.
 */
function Timeline({ lane, events }: { lane: Lane; events: StoryEvent[] }) {
    const color = laneColor(lane);

    return (
        <ol className="relative">
            {events.map((event, index) => {
                const Icon = kindIcons[event.kind];
                const highlighted = event.kind === 'person';
                const face = event.commit?.author ?? event.person;

                return (
                    <li
                        key={`${event.time}-${event.title}`}
                        className="relative grid grid-cols-[3rem_1.75rem_minmax(0,1fr)] gap-x-3 pb-5 last:pb-0"
                    >
                        <time className="pt-0.5 text-right font-mono text-[13px] text-muted-foreground tabular-nums">
                            {event.time}
                        </time>
                        <span
                            aria-hidden
                            className="relative flex justify-center"
                        >
                            {index < events.length - 1 && (
                                <span
                                    className="absolute top-3 -bottom-5 w-0.5"
                                    style={{ backgroundColor: color }}
                                />
                            )}
                            {face ? (
                                <img
                                    src={face.avatar}
                                    alt=""
                                    width={28}
                                    height={28}
                                    loading="lazy"
                                    className="relative size-7 rounded-full bg-muted ring-4 ring-card"
                                    style={{
                                        boxShadow: `0 0 0 2px ${color}`,
                                    }}
                                />
                            ) : (
                                <span
                                    className={cn(
                                        'relative mt-1.5 rounded-full ring-4 ring-card',
                                        highlighted ? 'size-3' : 'size-2.5',
                                    )}
                                    style={{
                                        backgroundColor: highlighted
                                            ? color
                                            : 'var(--card)',
                                        boxShadow: `inset 0 0 0 2px ${color}`,
                                    }}
                                />
                            )}
                        </span>
                        <div className="min-w-0">
                            <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span className="font-medium">
                                    <EventTitle event={event} />
                                </span>
                                <span className="inline-flex items-center gap-1 rounded border px-1.5 text-[11px] leading-4 text-muted-foreground">
                                    <Icon aria-hidden className="size-3" />
                                    {kindLabels[event.kind]}
                                </span>
                            </p>
                            {event.body && (
                                <p className="mt-1 text-muted-foreground">
                                    <WordmarkText>{event.body}</WordmarkText>
                                </p>
                            )}
                            {event.commit && (
                                <JoinCommit commit={event.commit} />
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

function Founders() {
    return (
        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {storyFounders.map((founder) => (
                <li key={founder.login}>
                    <Link
                        href={`/@${founder.login}`}
                        className="flex flex-col items-center gap-2 rounded-md border px-3 py-4 text-center no-underline! transition-colors hover:bg-muted"
                    >
                        <img
                            src={`https://github.com/${founder.login}.png?size=128`}
                            alt=""
                            width={64}
                            height={64}
                            loading="lazy"
                            className="size-16 rounded-full bg-muted"
                        />
                        <span className="min-w-0">
                            <span className="block truncate font-medium">
                                {founder.name}
                            </span>
                            <span className="block truncate font-mono text-xs text-muted-foreground">
                                @{founder.login}
                            </span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function Stream({ stream }: { stream: StoryStream }) {
    return (
        <div className="aspect-video w-full overflow-hidden rounded-lg border bg-muted">
            <iframe
                src={`https://www.youtube-nocookie.com/embed/${stream.videoId}`}
                title={`${stream.title} of building /dev/null, on YouTube`}
                loading="lazy"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                referrerPolicy="strict-origin-when-cross-origin"
                allowFullScreen
                className="size-full"
            />
        </div>
    );
}

function ContributorCard({ contributor }: { contributor: StoryContributor }) {
    return (
        <>
            <img
                src={`https://github.com/${contributor.login}.png?size=64`}
                alt=""
                width={28}
                height={28}
                loading="lazy"
                className="size-7 shrink-0 rounded-full bg-muted"
            />
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium">
                    @{contributor.login}
                </span>
                <span className="block font-mono text-xs text-muted-foreground tabular-nums">
                    {contributor.commits}{' '}
                    {contributor.commits === 1 ? 'commit' : 'commits'}
                </span>
            </span>
        </>
    );
}

/**
 * Contributors on /dev/null link to their profile, with their profile card on hover. Everyone else links to GitHub.
 */
function Contributors({ contributors }: { contributors: StoryContributor[] }) {
    const className =
        'flex items-center gap-2.5 rounded-md border px-2.5 py-2 no-underline! transition-colors hover:bg-muted';

    return (
        <ul className="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-2">
            {contributors.map((contributor) => (
                <li key={contributor.login}>
                    {contributor.username === null ? (
                        <a
                            href={`https://github.com/${contributor.login}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={className}
                        >
                            <ContributorCard contributor={contributor} />
                        </a>
                    ) : (
                        <UserPreview
                            user={{ username: contributor.username }}
                            className="block"
                        >
                            <Link
                                href={show({ username: contributor.username })}
                                aria-label={`@${contributor.username} on /dev/null`}
                                className={className}
                            >
                                <ContributorCard contributor={contributor} />
                            </Link>
                        </UserPreview>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function Show({
    contributors,
}: {
    contributors: StoryContributor[] | null;
}) {
    const { repository } = usePage().props;
    return (
        <>
            <Head title="About" />

            <DocumentPage title="About">
                <section className="space-y-3 px-4 py-6 text-[15px] text-pretty sm:px-6">
                    <p>
                        <WordmarkText>/dev/null</WordmarkText> started as a
                        stream, live from the JetBrains office. On October 15,
                        2026, Nuno Maduro, Brent Roose, Matthieu Napoli, and
                        Pauline Vos opened an empty repository and built a full
                        social network for developers in 48 hours, in the open.
                        GitHub is the only way in, posts are commits, and the
                        timeline is a log.
                    </p>
                    <p>
                        Everything was written from scratch with{' '}
                        <ExternalLink href="https://www.php.net">
                            PHP
                        </ExternalLink>
                        ,{' '}
                        <ExternalLink href="https://laravel.com">
                            Laravel
                        </ExternalLink>
                        ,{' '}
                        <ExternalLink href="https://inertiajs.com">
                            Inertia
                        </ExternalLink>
                        ,{' '}
                        <ExternalLink href="https://react.dev">
                            React
                        </ExternalLink>
                        , and{' '}
                        <ExternalLink href="https://cloud.laravel.com">
                            Laravel Cloud
                        </ExternalLink>
                        , in{' '}
                        <ExternalLink href="https://www.jetbrains.com/phpstorm">
                            JetBrains PhpStorm
                        </ExternalLink>{' '}
                        and{' '}
                        <ExternalLink href="https://jb.gg/nuno">
                            JetBrains Air
                        </ExternalLink>
                        .
                    </p>
                    <p>
                        Anyone could open a pull request, and hundreds did. This
                        is how the first days went.
                    </p>
                </section>

                <DocumentSection id="before" title="Before">
                    <Timeline lane={5} events={storyBefore} />
                </DocumentSection>

                {storyStreams.map((stream, index) => (
                    <DocumentSection
                        key={stream.id}
                        id={stream.id}
                        title={stream.title}
                    >
                        <p className="-mt-2 font-mono text-[13px] text-muted-foreground">
                            {stream.date}
                        </p>
                        <Stream stream={stream} />
                        {index === 0 && (
                            <>
                                <p>
                                    Four people started it, live, from the
                                    JetBrains office.
                                </p>
                                <Founders />
                            </>
                        )}
                        <DocumentList items={stream.highlights} />
                    </DocumentSection>
                ))}

                <DocumentSection id="after" title="After">
                    <p>
                        Then the stream ended, and the developers kept coming.
                    </p>
                    <div className="pt-2">
                        <Timeline lane={storyAfterLane} events={storyAfter} />
                    </div>
                </DocumentSection>

                <DocumentSection id="contributors" title="Contributors">
                    <p>Everyone who has a commit on production. Thank you.</p>
                    {contributors ? (
                        <Contributors contributors={contributors} />
                    ) : (
                        <p className="text-muted-foreground">
                            GitHub couldn't be reached. Try again in a minute.
                        </p>
                    )}
                    <p className="text-muted-foreground">
                        Want to be on this list?{' '}
                        <a
                            href={`https://github.com/${repository}`}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Open a pull request
                        </a>
                        .
                    </p>
                </DocumentSection>
            </DocumentPage>
        </>
    );
}

Show.layout = AppLayout;
