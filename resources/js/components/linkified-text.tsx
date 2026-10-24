import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Fragment } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import AppLogoIcon from '@/components/app-logo-icon';
import AppWordmark from '@/components/app-wordmark';
import { CertificateText } from '@/components/certificate-text';
import { GitHubIcon } from '@/components/github-icon';
import { UserPreview } from '@/components/user-preview';
import { typedProse, withoutCode } from '@/lib/code';
import type { Color } from '@/lib/colors';
import { colorsIn } from '@/lib/colors';
import { mentionPattern } from '@/lib/mentions';
import {
    hrefFor,
    shortenUrl,
    urlPattern,
    withoutTrailingPunctuation,
} from '@/lib/urls';
import { cn, shortHash } from '@/lib/utils';
import { show } from '@/routes/mention';
import type { BodySegment, Commit } from '@/types';

// A link to a commit on dev/null itself, nothing after its id, so it can show as the author and the commit's hash.
const commitLinkPattern =
    /^https?:\/\/(?:www\.)?dev-null\.sh\/@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})\/commits\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i;
const gitHubHostPattern = /^(?:www\.)?github\.com$/i;
const gitHubOwnerPattern =
    /^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/;
const gitHubRepositoryPattern = /^[A-Za-z0-9._-]{1,100}$/;
const gitHubNumberPattern = /^[1-9][0-9]{0,9}$/;
const gitHubShaPattern = /^[0-9a-f]{7,40}$/i;
// Two refs compared, "base...head" or "base..head", each a branch, a tag or a commit.
const gitHubComparisonPattern =
    /^([A-Za-z0-9_][A-Za-z0-9._/-]*?)(\.{2,3})([A-Za-z0-9_][A-Za-z0-9._/-]*)$/;
const gitHubTagPattern =
    /^[A-Za-z0-9_][A-Za-z0-9._+-]*(?:\/[A-Za-z0-9._+-]+)*$/;
// First path segments that are GitHub's own pages rather than an owner, like "github.com/features/actions".
const gitHubReservedPaths = new Set(
    [
        'about account apps blog codespaces collections contact copilot customer-stories dashboard education enterprise',
        'enterprises events explore features git-guides home issues join login logout marketplace mobile models new',
        'notifications orgs organizations partners premium-support pricing pulls readme resources search security',
        'settings signup site solutions sponsors stars team topics trending users watching',
    ]
        .join(' ')
        .split(' '),
);

/**
 * Every link in the prose being typed, in order and once each, as the server finds them for the link card.
 */
export function typedLinks(text: string): string[] {
    const links = new Set<string>();

    for (const prose of typedProse(text)) {
        for (const match of prose.matchAll(urlPattern)) {
            const href = hrefFor(withoutTrailingPunctuation(match[0]));

            if (href !== null) {
                links.add(href);
            }
        }
    }

    return [...links];
}

/**
 * Whether the prose outside code mentions someone, by the same rules that link a mention.
 */
export function hasMention(segments: BodySegment[]): boolean {
    return segments.some(
        (segment) =>
            segment.type === 'text' &&
            segment.text.search(mentionPattern) !== -1,
    );
}

// Like X, the hidden parts of a shortened URL stay in the text, so copying it or a screen reader gets the whole URL.
function ShortenedUrl({ url }: { url: string }) {
    const { hiddenStart, shown, hiddenEnd } = shortenUrl(url);
    const shownCharacters = Array.from(shown);

    return (
        <>
            {hiddenStart && <span className="text-[0px]">{hiddenStart}</span>}
            {hiddenEnd ? (
                <>
                    {shownCharacters.slice(0, -1).join('')}
                    {/* The ellipsis never wraps onto a line of its own. */}
                    <span className="whitespace-nowrap">
                        {shownCharacters.at(-1)}
                        <span className="text-[0px]">{hiddenEnd}</span>
                        <span aria-hidden="true" className="select-none">
                            …
                        </span>
                    </span>
                </>
            ) : (
                shown
            )}
        </>
    );
}

// Like a shortened URL, the whole URL stays in the text, so copying it keeps the link.
function CommitLink({
    url,
    username,
    commit,
    isStatic,
}: {
    url: string;
    username: string;
    commit: string;
    isStatic: boolean;
}) {
    const hash = shortHash(commit);
    const label = (
        <>
            <span className="text-[0px]">{url}</span>
            <span
                aria-hidden="true"
                className="font-mono text-[0.92em] wrap-anywhere select-none"
            >
                {/* The mark never wraps onto a line of its own. */}
                <span className="whitespace-nowrap text-muted-foreground">
                    <AppLogoIcon className="mr-[0.2em] inline-block size-[1.15em] fill-foreground align-[-0.25em]" />
                    {username.charAt(0)}
                </span>
                <span className="text-muted-foreground">
                    {username.slice(1)}/
                </span>
                {hash}
            </span>
        </>
    );

    if (isStatic) {
        return (
            <span title={url} className="text-primary">
                {label}
            </span>
        );
    }

    return (
        <Link
            href={CommitController.show({ user: username, commit })}
            title={url}
            aria-label={`@${username}’s commit ${hash}`}
            className="text-primary hover:underline"
        >
            {label}
        </Link>
    );
}

/**
 * What a GitHub link points at inside a repository, as GitHub writes it after the repository's name ("#42",
 * "@4fa008f") and as a screen reader reads it ("issue #42", "commit 4fa008f").
 */
type GitHubItem = { suffix: string; description: string };

type GitHubTarget = {
    owner: string;
    repository: string | null;
    item: GitHubItem | null;
};

// A commit as GitHub shortens it; any other ref as it is.
function shortRef(ref: string): string {
    return gitHubShaPattern.test(ref) ? ref.slice(0, 7) : ref;
}

const gitHubThreads: Record<string, string> = {
    issues: 'issue',
    pull: 'pull request',
    discussions: 'discussion',
};

// The anchors GitHub gives a comment or a review in an issue, a pull request or a discussion.
const gitHubThreadAnchors: Record<string, [RegExp, string][]> = {
    issues: [[/^#issuecomment-[1-9][0-9]*$/, 'comment']],
    pull: [
        [/^#(?:issuecomment-|discussion_r)[1-9][0-9]*$/, 'comment'],
        [/^#pullrequestreview-[1-9][0-9]*$/, 'review'],
    ],
    discussions: [[/^#discussioncomment-[1-9][0-9]*$/, 'comment']],
};

/**
 * An issue, a pull request or a discussion, or one of their comments or reviews, as GitHub writes it: "#42", or
 * "#42 (comment)".
 */
function gitHubThread(
    kind: string,
    rest: string[],
    hash: string,
): GitHubItem | null {
    const thread = gitHubThreads[kind];

    if (
        thread === undefined ||
        rest.length !== 1 ||
        !gitHubNumberPattern.test(rest[0])
    ) {
        return null;
    }

    const reference = `${thread} #${rest[0]}`;

    if (hash === '') {
        return { suffix: `#${rest[0]}`, description: reference };
    }

    const anchor = gitHubThreadAnchors[kind].find(([pattern]) =>
        pattern.test(hash),
    )?.[1];

    return anchor
        ? {
              suffix: `#${rest[0]} (${anchor})`,
              description: `${anchor} on ${reference}`,
          }
        : null;
}

const gitHubLinesPattern = /^#L([1-9][0-9]*)(?:-L([1-9][0-9]*))?$/;

/**
 * A file, maybe some of its lines, or a folder, by its last name only so it stays short: "TheAlgorithm.php",
 * "TheAlgorithm.php:10-20" or "Services/". The branch or commit it was read at only stays in the URL.
 */
function gitHubFile(
    kind: 'blob' | 'tree',
    path: string[],
    hash: string,
): GitHubItem | null {
    let names: string[];

    try {
        names = path.map((segment) => decodeURIComponent(segment));
    } catch {
        return null;
    }

    const name = names[names.length - 1];
    const full = names.join('/');

    if (kind === 'tree') {
        return hash === ''
            ? { suffix: `:${name}/`, description: `folder ${full}` }
            : null;
    }

    if (hash === '') {
        return { suffix: `:${name}`, description: `file ${full}` };
    }

    const lines = gitHubLinesPattern.exec(hash);

    if (!lines) {
        return null;
    }

    const [, first, last = first] = lines;

    return last === first
        ? {
              suffix: `:${name}:${first}`,
              description: `file ${full}, line ${first}`,
          }
        : {
              suffix: `:${name}:${first}-${last}`,
              description: `file ${full}, lines ${first} to ${last}`,
          };
}

function gitHubItem(path: string[], hash: string): GitHubItem | null {
    const [first, ...rest] = path;
    const kind = first.toLowerCase();

    // Own keys only: a path segment like "constructor" names no thread.
    if (Object.hasOwn(gitHubThreads, kind)) {
        return gitHubThread(kind, rest, hash);
    }

    // After the branch or commit, the path, every segment named.
    if (
        (kind === 'blob' || kind === 'tree') &&
        rest.length >= 2 &&
        rest.every((segment) => segment !== '')
    ) {
        return gitHubFile(kind, rest.slice(1), hash);
    }

    if (hash !== '') {
        return null;
    }

    if (
        kind === 'commit' &&
        rest.length === 1 &&
        gitHubShaPattern.test(rest[0])
    ) {
        const commit = shortRef(rest[0]);

        return { suffix: `@${commit}`, description: `commit ${commit}` };
    }

    const tag = rest.slice(1).join('/');

    if (
        kind === 'releases' &&
        rest[0] === 'tag' &&
        gitHubTagPattern.test(tag)
    ) {
        return { suffix: `@${tag}`, description: `release ${tag}` };
    }

    const comparison =
        kind === 'compare'
            ? gitHubComparisonPattern.exec(rest.join('/'))
            : null;

    if (comparison && !comparison[3].includes('..')) {
        const refs = `${shortRef(comparison[1])}${comparison[2]}${shortRef(comparison[3])}`;

        return { suffix: `@${refs}`, description: `comparison ${refs}` };
    }

    return null;
}

/**
 * A link to a GitHub profile or organization, to a repository, or to an issue, a pull request, a discussion, a commit,
 * a comparison, a release, a file or a folder in it, nothing after them but a comment's or some lines' anchor, so it
 * can show as GitHub writes it.
 */
function gitHubLinkTo(href: string): GitHubTarget | null {
    let url: URL;

    try {
        url = new URL(href);
    } catch {
        return null;
    }

    const segments = url.pathname.slice(1).split('/');

    if (
        !gitHubHostPattern.test(url.hostname) ||
        url.username !== '' ||
        url.password !== '' ||
        url.port !== '' ||
        url.search !== ''
    ) {
        return null;
    }

    // An organization's own page names it like its profile does.
    const [owner, repository, ...path] =
        segments.length === 2 && segments[0].toLowerCase() === 'orgs'
            ? segments.slice(1)
            : segments;

    if (
        !gitHubOwnerPattern.test(owner) ||
        gitHubReservedPaths.has(owner.toLowerCase())
    ) {
        return null;
    }

    if (repository === undefined) {
        return url.hash === '' ? { owner, repository: null, item: null } : null;
    }

    if (!gitHubRepositoryPattern.test(repository)) {
        return null;
    }

    if (path.length === 0) {
        return url.hash === '' ? { owner, repository, item: null } : null;
    }

    const item = gitHubItem(path, url.hash);

    return item ? { owner, repository, item } : null;
}

// Like a dev/null commit link, the whole URL stays in the text, so copying it keeps the link.
function GitHubLink({
    url,
    href,
    owner,
    repository,
    item,
    isStatic,
}: {
    url: string;
    href: string;
    owner: string;
    repository: string | null;
    item: GitHubItem | null;
    isStatic: boolean;
}) {
    const name = repository === null ? owner : `${owner}/${repository}`;
    const label = (
        <>
            <span className="text-[0px]">{url}</span>
            <span
                aria-hidden="true"
                className="font-mono text-[0.92em] wrap-anywhere select-none"
            >
                {/* The mark never wraps onto a line of its own. */}
                <span
                    className={cn('whitespace-nowrap', {
                        'text-muted-foreground': repository !== null,
                    })}
                >
                    <GitHubIcon className="mr-[0.2em] inline-block size-[1.15em] align-[-0.25em] text-foreground" />
                    {owner.charAt(0)}
                </span>
                {repository === null ? (
                    owner.slice(1)
                ) : (
                    <>
                        <span className="text-muted-foreground">
                            {owner.slice(1)}/
                        </span>
                        {repository}
                        {item?.suffix}
                    </>
                )}
            </span>
        </>
    );

    if (isStatic) {
        return (
            <span title={href} className="text-primary">
                {label}
            </span>
        );
    }

    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer nofollow ugc"
            title={href}
            aria-label={`${name}${item ? ` ${item.description}` : ''} on GitHub`}
            className="text-primary hover:underline"
        >
            {label}
        </a>
    );
}

function withPasteis(text: string, pasteisMode: boolean): ReactNode {
    if (!pasteisMode) {
        return <CertificateText text={text} />;
    }

    return text
        .split(
            /(?<![\p{L}\p{M}\p{N}_])(pastel|pasteis|pastéis)(?![\p{L}\p{M}\p{N}_])/giu,
        )
        .map((part, index) =>
            index % 2 === 0 ? (
                <CertificateText key={index} text={part} />
            ) : (
                <img
                    key={index}
                    src="/pastel-de-nata.svg"
                    alt={part}
                    title={part}
                    width={20}
                    height={20}
                    className="inline-block size-[1.25em] object-contain align-[-0.2em]"
                />
            ),
        );
}

function withWordmarks(
    text: string,
    offset: number,
    pasteisMode: boolean,
): ReactNode[] {
    return text
        .split(
            /(?<![\p{L}\p{M}\p{N}_./-])(\/dev\/null)(?![\p{L}\p{M}\p{N}_/-])/u,
        )
        .map((part, index) =>
            index % 2 === 0 ? (
                <Fragment key={`text-${offset}-${index}`}>
                    {withPasteis(part, pasteisMode)}
                </Fragment>
            ) : (
                <span
                    key={`wordmark-${offset}-${index}`}
                    title={part}
                    className="font-semibold tracking-tight whitespace-nowrap"
                >
                    <AppWordmark />
                </span>
            ),
        );
}

/**
 * The hex colors found in the whole text that fall in this slice of it, as their value with a swatch of the color.
 */
function withColors(
    text: string,
    offset: number,
    colors: Color[],
    pasteisMode: boolean,
): ReactNode[] {
    const parts: ReactNode[] = [];
    let position = 0;

    for (const color of colors) {
        const index = color.index - offset;

        if (index < position || index + color.length > text.length) {
            continue;
        }

        parts.push(
            ...withWordmarks(
                text.slice(position, index),
                offset + position,
                pasteisMode,
            ),
            <span
                key={`color-${color.index}`}
                className="whitespace-nowrap"
                data-test="color"
            >
                <span
                    aria-hidden="true"
                    className="mr-0.5 inline-block size-[0.75em] rounded-sm border border-border align-[-0.05em]"
                    style={{ backgroundColor: `#${color.value}` }}
                />
                {text.slice(index, index + color.length)}
            </span>,
        );

        position = index + color.length;
    }

    parts.push(
        ...withWordmarks(text.slice(position), offset + position, pasteisMode),
    );

    return parts;
}

function withMentions(
    text: string,
    offset: number,
    mentions: Commit['mentions'],
    colors: Color[],
    pasteisMode: boolean,
    isStatic: boolean,
): ReactNode[] {
    const parts: ReactNode[] = [];
    let position = 0;

    for (const match of text.matchAll(mentionPattern)) {
        const username = match[1].toLowerCase();
        const userId =
            mentions && Object.hasOwn(mentions, username)
                ? mentions[username]
                : null;

        parts.push(
            ...withColors(
                text.slice(position, match.index),
                offset + position,
                colors,
                pasteisMode,
            ),
            isStatic || !userId ? (
                match[0]
            ) : (
                <UserPreview
                    key={`mention-${offset + match.index}`}
                    user={{ username: match[1] }}
                    userId={userId}
                >
                    <Link
                        href={show(userId)}
                        className="font-medium text-primary hover:underline"
                        data-test="mention"
                    >
                        {match[0]}
                    </Link>
                </UserPreview>
            ),
        );

        position = match.index + match[0].length;
    }

    parts.push(
        ...withColors(
            text.slice(position),
            offset + position,
            colors,
            pasteisMode,
        ),
    );

    return parts;
}

export function LinkifiedText({
    text,
    mentions,
    pasteisMode = false,
    isStatic = false,
    startsText = false,
}: {
    text: string;
    mentions: Commit['mentions'];
    pasteisMode?: boolean;
    isStatic?: boolean;
    // Whether the text is a whole body, so its start is the start of a line.
    startsText?: boolean;
}) {
    const parts: ReactNode[] = [];
    // Colors are found in the prose only, for callers that pass a whole body, code included.
    const colors = colorsIn(withoutCode(text), startsText);
    let position = 0;

    for (const match of text.matchAll(urlPattern)) {
        const linkText = withoutTrailingPunctuation(match[0]);
        const href = hrefFor(linkText);

        if (href === null) {
            continue;
        }

        const commitLink = commitLinkPattern.exec(linkText);
        const gitHubLink = gitHubLinkTo(href);

        parts.push(
            ...withMentions(
                text.slice(position, match.index),
                position,
                mentions,
                colors,
                pasteisMode,
                isStatic,
            ),
            commitLink ? (
                <CommitLink
                    key={match.index}
                    url={linkText}
                    username={commitLink[1]}
                    commit={commitLink[2].toLowerCase()}
                    isStatic={isStatic}
                />
            ) : gitHubLink ? (
                <GitHubLink
                    key={match.index}
                    url={linkText}
                    href={href}
                    owner={gitHubLink.owner}
                    repository={gitHubLink.repository}
                    item={gitHubLink.item}
                    isStatic={isStatic}
                />
            ) : isStatic ? (
                linkText
            ) : (
                <a
                    key={match.index}
                    href={href}
                    target="_blank"
                    rel="noopener noreferrer nofollow ugc"
                    title={href}
                    className="wrap-anywhere text-primary hover:underline"
                >
                    <ShortenedUrl url={linkText} />
                </a>
            ),
        );

        position = match.index + linkText.length;
    }

    parts.push(
        ...withMentions(
            text.slice(position),
            position,
            mentions,
            colors,
            pasteisMode,
            isStatic,
        ),
    );

    return <>{parts}</>;
}
