import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import AppLogoIcon from '@/components/app-logo-icon';
import AppWordmark from '@/components/app-wordmark';
import { UserPreview } from '@/components/user-preview';
import { typedProse } from '@/lib/code';
import { shortenUrl } from '@/lib/urls';
import { shortHash } from '@/lib/utils';
import { show } from '@/routes/user';

// Keep these rules in sync with app/Services/Urls.php and app/Services/Mentions.php.

// Top-level domains that a bare domain like "nativephp.com" may end with. It is an allowlist because most file
// extensions are also real TLDs. Leave out any that read like code or files (sh, py, rs, md, pl, cc, in, mk, ml, so,
// tf, ps, am, cl, gd, cr, do, zip, mov, ...) or like a property in code (user.id, user.name, app.run, ...).
const topLevelDomains = [
    'com org net edu gov mil info biz io dev app co ai me xyz tech gg tv fm ly to is blog cloud site online design',
    'studio tools software codes wiki news website club ninja rocks academy guide social digital agency fyi lol live',
    'uk de fr nl es it pt br ca au jp ch se no dk fi be at ie nz us eu cz sk hu ro bg gr hr si ee lv lt ru ua tr il za',
    'mx ar kr cn tw hk sg vn th',
]
    .join(' ')
    .split(' ');

// A URL with a scheme, or a bare domain: ASCII labels ending in an allowed TLD, optionally followed by a path, query or
// fragment. A bare domain can't follow a letter, digit, "_", ".", "/", "@" or "-". Its TLD must be all lowercase or all
// uppercase, and must end the word without a "(" or another label after it. There is no case-insensitive flag, so
// that the TLD's case can be checked.
const urlPattern = new RegExp(
    String.raw`\b[Hh][Tt][Tt][Pp][Ss]?:\/\/[^\s<>]+` +
        String.raw`|(?<![\p{L}\p{N}_.\/@-])(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+` +
        String.raw`(?:${[...topLevelDomains, ...topLevelDomains.map((domain) => domain.toUpperCase())].join('|')})` +
        String.raw`(?![\p{L}\p{N}_(-]|\.[A-Za-z0-9])(?:[\/?#][^\s<>]*)?`,
    'gu',
);
// A GitHub username after an "@" that doesn't follow a letter, digit, "_", ".", "/", "@" or "-", and doesn't run
// into a path or a domain, so emails, "@inertiajs/react" and "@vue.js" are not mentions.
const mentionPattern =
    /(?<![\p{L}\p{N}_./@-])@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})(?![A-Za-z0-9_@/-]|\.[A-Za-z0-9])/gu;
// A link to a commit on dev/null itself, nothing after its id, so it can show as the author and the commit's hash.
const commitLinkPattern =
    /^https?:\/\/(?:www\.)?dev-null\.sh\/@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})\/commits\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i;
const schemePattern = /^https?:\/\//i;
const maxUrlLength = 2048;
const brackets = [
    ['(', ')'],
    ['[', ']'],
] as const;

function count(text: string, character: string): number {
    return text.split(character).length - 1;
}

function withoutTrailingPunctuation(candidate: string): string {
    let url = candidate;
    let previous: string;

    do {
        previous = url;
        url = url.replace(/[.,;:!?'"]+$/u, '');

        for (const [open, close] of brackets) {
            if (url.endsWith(close) && count(url, open) < count(url, close)) {
                url = url.slice(0, -1);
            }
        }
    } while (url !== previous);

    return url;
}

function hrefFor(linkText: string): string | null {
    const href = schemePattern.test(linkText)
        ? linkText
        : `https://${linkText}`;

    return href.endsWith('://') || Array.from(href).length > maxUrlLength
        ? null
        : href;
}

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
}: {
    url: string;
    username: string;
    commit: string;
}) {
    const hash = shortHash(commit);

    return (
        <Link
            href={CommitController.show({ user: username, commit })}
            title={url}
            aria-label={`@${username}’s commit ${hash}`}
            className="text-primary hover:underline"
        >
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
        </Link>
    );
}

function withPasteis(text: string, pasteisMode: boolean): ReactNode {
    if (!pasteisMode) {
        return text;
    }

    return text
        .split(
            /(?<![\p{L}\p{M}\p{N}_])(pastel|pasteis|pastéis)(?![\p{L}\p{M}\p{N}_])/giu,
        )
        .map((part, index) =>
            index % 2 === 0 ? (
                part
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

function withWordmarks(text: string, pasteisMode: boolean): ReactNode[] {
    return text
        .split(
            /(?<![\p{L}\p{M}\p{N}_./-])(\/dev\/null)(?![\p{L}\p{M}\p{N}_/-])/u,
        )
        .map((part, index) =>
            index % 2 === 0 ? (
                withPasteis(part, pasteisMode)
            ) : (
                <span
                    key={`wordmark-${index}`}
                    title={part}
                    className="font-semibold tracking-tight whitespace-nowrap"
                >
                    <AppWordmark />
                </span>
            ),
        );
}

function withMentions(
    text: string,
    offset: number,
    pasteisMode: boolean,
    isStatic: boolean,
): ReactNode[] {
    const parts: ReactNode[] = [];
    let position = 0;

    for (const match of text.matchAll(mentionPattern)) {
        parts.push(
            ...withWordmarks(text.slice(position, match.index), pasteisMode),
            isStatic ? (
                match[0]
            ) : (
                <UserPreview
                    key={`mention-${offset + match.index}`}
                    user={{ username: match[1] }}
                >
                    <Link
                        href={show({ username: match[1] })}
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

    parts.push(...withWordmarks(text.slice(position), pasteisMode));

    return parts;
}

export function LinkifiedText({
    text,
    pasteisMode = false,
    isStatic = false,
}: {
    text: string;
    pasteisMode?: boolean;
    isStatic?: boolean;
}) {
    const parts: ReactNode[] = [];
    let position = 0;

    for (const match of text.matchAll(urlPattern)) {
        const linkText = withoutTrailingPunctuation(match[0]);
        const href = hrefFor(linkText);

        if (href === null) {
            continue;
        }

        const commitLink = commitLinkPattern.exec(linkText);

        parts.push(
            ...withMentions(
                text.slice(position, match.index),
                position,
                pasteisMode,
                isStatic,
            ),
            isStatic ? (
                linkText
            ) : commitLink ? (
                <CommitLink
                    key={match.index}
                    url={linkText}
                    username={commitLink[1]}
                    commit={commitLink[2].toLowerCase()}
                />
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
        ...withMentions(text.slice(position), position, pasteisMode, isStatic),
    );

    return <>{parts}</>;
}
