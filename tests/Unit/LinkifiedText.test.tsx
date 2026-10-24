/// <reference types="node" />
import assert from 'node:assert/strict';
import { App } from '@inertiajs/react';
import type { ComponentProps, ReactElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { test } from 'vite-plus/test';
import { BodyCounter } from '../../resources/js/components/body-counter';
import { ConversationList } from '../../resources/js/components/chat/conversation-list';
import { MessageText } from '../../resources/js/components/chat/message-parts';
import { CommitBody } from '../../resources/js/components/commit-body';
import {
    LinkifiedText,
    typedLinks,
} from '../../resources/js/components/linkified-text';
import { WordmarkText } from '../../resources/js/components/wordmark-text';
import { bodySegments } from '../../resources/js/lib/code';
import { bodyCharacterCount } from '../../resources/js/lib/commit';
import type { User } from '../../resources/js/types';

test('counts each prose URL once per occurrence, without discounting code or oversized links', () => {
    const cases: [string, number][] = [
        ['é🚀 hello', 8],
        ['example.com', 11],
        ['https://example.com/long-path-here', 23],
        ['éhttps://example.com/long-path-here', 35],
        ['٤https://example.com/long-path-here', 35],
        ['\u0301https://example.com/long-path-here', 35],
        ['\u203fhttps://example.com/long-path-here', 35],
        ['_https://example.com/long-path-here', 35],
        ['🚀https://example.com/long-path-here', 24],
        [
            'https://example.com/long-path-here https://example.com/long-path-here',
            47,
        ],
        ['(https://example.com/long-path-here).', 26],
        ['https://example.com/a_(long_article).', 24],
        ['example.com/long-path-here', 23],
        ['`https://example.com/long-path-here`', 36],
        ['```\nhttps://example.com/long-path-here\n```', 42],
        ['`https://example.com/long-path-here', 24],
        ['https://', 8],
        ['https://...', 11],
        ['javascript:alert(1)', 19],
        ['https://example.com/' + 'x'.repeat(2028), 23],
        ['https://example.com/' + 'x'.repeat(2029), 2049],
        ['  hello\r\n\r\n\r\nhttps://example.com/long-path-here  ', 30],
    ];
    for (const [body, length] of cases) {
        assert.equal(bodyCharacterCount(body), length, body);
    }
});

test('trims the draft before recognizing fences so code keeps its blank lines', () => {
    const body = '  ```\n' + 'a'.repeat(3990) + '\n'.repeat(6) + '```';

    assert.equal(bodyCharacterCount(body), 4003);
});

test('does not discount a URL attached to a Unicode word character', () => {
    const body = 'a'.repeat(3975) + ' éhttps://example.com/long-path-here';

    assert.equal(bodyCharacterCount(body), 4011);
    assert.deepEqual(typedLinks(body), []);
});

test('the counter discounts commit links but not issue links', () => {
    const body = 'a'.repeat(3976) + ' https://example.com/long-path-here';
    assert.match(
        renderToStaticMarkup(<BodyCounter body={body} />),
        /0 characters left/,
    );
    assert.match(
        renderToStaticMarkup(<BodyCounter body={body} isIssue />),
        /-11 characters left/,
    );
    assert.match(
        renderToStaticMarkup(<BodyCounter body={body + ' x'} />),
        /-2 characters left/,
    );
});

function renderPage(content: ReactElement): string {
    return renderToStaticMarkup(
        <App
            initialComponent={() => content}
            initialPage={
                {
                    component: 'Commit',
                    url: '/',
                    version: null,
                    props: { errors: {}, auth: { user: null, pasteis: null } },
                    clearHistory: false,
                    encryptHistory: false,
                } as unknown as ComponentProps<typeof App>['initialPage']
            }
        />,
    );
}

test('keeps stable mention links alongside shortened URLs and their full copyable text', () => {
    const id = '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b';
    const url =
        'https://www.example.com/docs/a-very-long-path-that-is-shortened';
    const text = `Thanks @ORIGINAL! See ${url} and @futuredev.`;
    const html = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={{ original: id }} />,
    );

    assert.ok(html.includes(`href="/mentions/${id}"`));
    assert.ok(html.includes('>@ORIGINAL</a>'));
    assert.equal(html.match(/data-test="mention"/g)?.length, 1);
    assert.ok(html.includes(`href="${url}"`));
    assert.ok(html.includes(`title="${url}"`));
    assert.ok(html.includes('aria-hidden="true"'));
    assert.equal(html.replace(/<[^>]*>/g, '').replace('…', ''), text);
});

test('leaves mentions without captured identities as plain text', () => {
    const html = renderToStaticMarkup(
        <LinkifiedText text="Thanks @original!" mentions={null} />,
    );

    assert.equal(html, 'Thanks @original!');
});

test('keeps captured mention identities in regular bodies alongside highlighted code', () => {
    const id = '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b';
    const html = renderPage(
        <CommitBody
            segments={[
                { type: 'text', text: 'Thanks @ORIGINAL and @unknown! ' },
                { type: 'code', code: '@ORIGINAL' },
                {
                    type: 'block',
                    language: 'php',
                    html: '<span class="hl-comment">// @ORIGINAL</span>',
                },
            ]}
            mentions={{ original: id }}
        />,
    );

    assert.ok(html.includes(`href="/mentions/${id}"`));
    assert.ok(html.includes('>@ORIGINAL</a>'));
    assert.equal(html.match(/data-test="mention"/g)?.length, 1);
    assert.ok(html.includes('and @unknown!'));
    assert.match(html, /<code[^>]*>@ORIGINAL<\/code>/);
    assert.match(
        html,
        /<pre[^>]*><span class="hl-comment">\/\/ @ORIGINAL<\/span>/,
    );
});

const url = 'https://www.example.com/a-long-path-to-the-release-notes';

for (const surface of ['commit', 'remote', 'optimistic remote', 'excerpt']) {
    test(`formats consecutive quote lines with inline content in a ${surface}`, () => {
        const body =
            '> First `code`\r\n> @original https://example.com :smile:\r\nNormal > text\n>Last';
        const mentions = { original: '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b' };
        const segments = bodySegments(body);
        const html = renderPage(
            surface.includes('remote') ? (
                <MessageText
                    message={{
                        body,
                        mentions,
                        body_segments:
                            surface === 'remote' ? segments : undefined,
                    }}
                    isMine={surface === 'optimistic remote'}
                />
            ) : (
                <CommitBody
                    segments={segments}
                    mentions={mentions}
                    isStatic={surface === 'excerpt'}
                />
            ),
        );

        const quotes = [
            ...html.matchAll(/<blockquote[^>]*>(.*?)<\/blockquote>/gs),
        ];
        assert.equal(quotes.length, 2);
        assert.equal(
            quotes[0][1].replace(/<[^>]+>/g, ''),
            'First code\r\n@original https://example.com 😄',
        );
        assert.match(quotes[0][1], /<code[^>]*>code<\/code>/);
        assert.equal(quotes[1][1], 'Last');
        assert.match(html, /<\/blockquote>Normal &gt; text<blockquote/);
        if (surface === 'excerpt') {
            assert.ok(!html.includes('<a'));
        } else {
            assert.ok(
                quotes[0][1].includes(`href="/mentions/${mentions.original}"`),
            );
            assert.ok(quotes[0][1].includes('href="https://example.com"'));
        }
    });
}

test('only treats prose at a line start as a quote and escapes quoted HTML', () => {
    const html = renderPage(
        <CommitBody
            segments={bodySegments(
                '`code`> literal\n```text\n> fenced\n```\n> <script>alert(1)</script>\n>\n> `> inline`\n\nAfter',
            )}
            mentions={null}
        />,
    );

    assert.equal(html.match(/<blockquote/g)?.length, 1);
    assert.match(html, /<\/code>&gt; literal/);
    assert.match(html, /<pre[^>]*>&gt; fenced<\/pre>/);
    assert.match(
        html,
        /&lt;script&gt;alert\(1\)&lt;\/script&gt;\n\n<code[^>]*>&gt; inline<\/code><\/blockquote>\nAfter/,
    );
    assert.ok(!html.includes('<script>'));
});

test('finds the full preview URL outside inline and fenced code', () => {
    const body = [
        '`https://inline.example.com`',
        '```text',
        'https://block.example.com',
        '```',
        `Read ${url}.`,
    ].join('\n');

    assert.deepEqual(typedLinks(body), [url]);
    assert.deepEqual(typedLinks('`https://inline.example.com`'), []);
});

test('shortens the displayed preview link while retaining the full URL', () => {
    const markup = renderToStaticMarkup(
        <LinkifiedText text={url} mentions={null} />,
    );

    assert.ok(markup.includes(`href="${url}"`));
    assert.ok(markup.includes(`title="${url}"`));
    assert.ok(markup.includes('example.com/a-long-path-to-the'));
    assert.ok(markup.includes('…'));
    assert.equal(
        markup
            .replace(/<span aria-hidden="true"[^>]*>.*?<\/span>/g, '')
            .replace(/<[^>]+>/g, ''),
        url,
    );
});

for (const [text, trailing] of [
    // A feed excerpt ends with an ellipsis right after its last word.
    ['7\nhttps://example.com/launch…', '…'],
    ['He said “https://example.com/launch”.', '”.'],
    ['‘https://example.com/launch’', '’'],
]) {
    test(`leaves an ellipsis or a curly quote out of the link: ${text}`, () => {
        const markup = renderToStaticMarkup(
            <LinkifiedText text={text} mentions={null} />,
        );

        assert.deepEqual(typedLinks(text), ['https://example.com/launch']);
        assert.ok(markup.includes('href="https://example.com/launch"'));
        assert.ok(markup.endsWith(`</a>${trailing}`));
    });
}

test('keeps every distinct card URL in order without joining prose across code', () => {
    assert.deepEqual(
        typedLinks(
            'https://first.example.com`code`https://second.example.com https://first.example.com',
        ),
        ['https://first.example.com', 'https://second.example.com'],
    );
});

for (const body of [
    'before `https://draft.example.com',
    '```text\nhttps://draft.example.com',
]) {
    test(`ignores card URLs in unfinished code: ${body}`, () => {
        assert.deepEqual(typedLinks(body), []);
    });
}

test('renders /dev/null as the wordmark while keeping its text', () => {
    const text = 'piped to /dev/null, then /dev/null. (/dev/null)';
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} />,
    );

    assert.equal(markup.match(/title="\/dev\/null"/g)?.length, 3);
    assert.equal(markup.replace(/<[^>]+>/g, ''), text);
});

test('leaves /dev/null inside longer paths, words, URLs and usernames as text', () => {
    const text =
        '/dev/nullable /dev/null/log a/dev/null /dev/null_1 /DEV/NULL https://example.com/dev/null @devnull';
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} />,
    );

    assert.ok(!markup.includes('title="/dev/null"'));
});

const commitUrl =
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8';

test('shows a dev/null commit link as its author and short hash', () => {
    const text = `same root cause as ${commitUrl}.`;
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} />,
    );

    assert.ok(
        markup.includes(
            'href="/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8"',
        ),
    );
    assert.ok(markup.includes(`title="${commitUrl}"`));
    assert.ok(markup.includes('aria-label="@Hacksore’s commit 5149ca8"'));
    assert.equal(
        markup.replace(/<[^>]+>/g, ''),
        `same root cause as ${commitUrl}Hacksore/5149ca8.`,
    );
    assert.equal(
        markup
            .replace(/<span aria-hidden="true"[^>]*>.*?<\/span><\/a>/g, '')
            .replace(/<[^>]+>/g, ''),
        text,
    );
});

test('shows a dev/null commit link as its author and short hash without a link when static', () => {
    const text = `same root cause as ${commitUrl}.`;
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} isStatic />,
    );

    assert.ok(!markup.includes('<a'));
    assert.ok(markup.includes(`title="${commitUrl}"`));
    assert.equal(
        markup.replace(/<[^>]+>/g, ''),
        `same root cause as ${commitUrl}Hacksore/5149ca8.`,
    );
});

for (const link of [
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8/photos/1',
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8?tab=replies',
    'https://example.com/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8',
    'https://dev-null.sh/@Hacksore',
]) {
    test(`keeps other links as URLs: ${link}`, () => {
        const markup = renderToStaticMarkup(
            <LinkifiedText text={link} mentions={null} />,
        );

        assert.ok(!markup.includes('aria-label'));
        assert.ok(markup.includes(`href="${link}"`));
    });
}

const repositoryUrl = 'https://github.com/laravel/framework';

test('shows a GitHub repository link as its owner and name', () => {
    const text = `built on ${repositoryUrl}.`;
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} />,
    );

    assert.ok(markup.includes(`href="${repositoryUrl}"`));
    assert.ok(markup.includes('target="_blank"'));
    assert.ok(markup.includes('rel="noopener noreferrer nofollow ugc"'));
    assert.ok(markup.includes(`title="${repositoryUrl}"`));
    assert.ok(markup.includes('aria-label="laravel/framework on GitHub"'));
    assert.equal(
        markup.replace(/<[^>]+>/g, ''),
        `built on ${repositoryUrl}laravel/framework.`,
    );
    assert.equal(
        markup
            .replace(/<span aria-hidden="true"[^>]*>.*?<\/span><\/a>/g, '')
            .replace(/<[^>]+>/g, ''),
        text,
    );
});

test('shows a GitHub repository link as its owner and name without a link when static', () => {
    const text = `built on ${repositoryUrl}.`;
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} isStatic />,
    );

    assert.ok(!markup.includes('<a'));
    assert.ok(markup.includes(`title="${repositoryUrl}"`));
    assert.equal(
        markup.replace(/<[^>]+>/g, ''),
        `built on ${repositoryUrl}laravel/framework.`,
    );
});

for (const [link, href] of [
    ['github.com/laravel/framework', 'https://github.com/laravel/framework'],
    [
        'https://www.github.com/laravel/framework',
        'https://www.github.com/laravel/framework',
    ],
    [
        'HTTPS://GitHub.com/laravel/framework',
        'HTTPS://GitHub.com/laravel/framework',
    ],
    [
        'http://github.com/dev-null-sh/dev-null.sh',
        'http://github.com/dev-null-sh/dev-null.sh',
    ],
]) {
    test(`shows GitHub repository links however they are written: ${link}`, () => {
        const markup = renderToStaticMarkup(
            <LinkifiedText text={link} mentions={null} />,
        );

        assert.ok(markup.includes(`href="${href}"`));
        assert.ok(markup.includes(' on GitHub"'));
        assert.equal(
            markup
                .replace(/<span aria-hidden="true"[^>]*>.*?<\/span><\/a>/g, '')
                .replace(/<[^>]+>/g, ''),
            link,
        );
    });
}

for (const [kind, path] of [
    ['issue', 'issues'],
    ['pull request', 'pull'],
]) {
    const url = `https://github.com/laravel/framework/${path}/42`;

    test(`shows a GitHub ${kind} link as its repository and number`, () => {
        const text = `fixed by ${url}.`;
        const markup = renderToStaticMarkup(
            <LinkifiedText text={text} mentions={null} />,
        );

        assert.ok(markup.includes(`href="${url}"`));
        assert.ok(markup.includes(`title="${url}"`));
        assert.ok(
            markup.includes(
                `aria-label="laravel/framework ${kind} #42 on GitHub"`,
            ),
        );
        assert.equal(
            markup.replace(/<[^>]+>/g, ''),
            `fixed by ${url}laravel/framework#42.`,
        );
        assert.equal(
            markup
                .replace(/<span aria-hidden="true"[^>]*>.*?<\/span><\/a>/g, '')
                .replace(/<[^>]+>/g, ''),
            text,
        );
    });

    test(`shows a GitHub ${kind} link as its repository and number without a link when static`, () => {
        const text = `fixed by ${url}.`;
        const markup = renderToStaticMarkup(
            <LinkifiedText text={text} mentions={null} isStatic />,
        );

        assert.ok(!markup.includes('<a'));
        assert.ok(markup.includes(`title="${url}"`));
        assert.equal(
            markup.replace(/<[^>]+>/g, ''),
            `fixed by ${url}laravel/framework#42.`,
        );
    });
}

const sha = '4fa008fe2c9b1d3e5f7a9b0c1d2e3f4a5b6c7d8e';

for (const [url, label, description] of [
    [
        'https://github.com/laravel/framework/commit/4fa008f',
        'laravel/framework@4fa008f',
        'laravel/framework commit 4fa008f',
    ],
    [
        `https://github.com/laravel/framework/commit/${sha}`,
        'laravel/framework@4fa008f',
        'laravel/framework commit 4fa008f',
    ],
    [
        'https://github.com/laravel/framework/compare/v12.0.0...v12.1.0',
        'laravel/framework@v12.0.0...v12.1.0',
        'laravel/framework comparison v12.0.0...v12.1.0',
    ],
    [
        `https://github.com/laravel/framework/compare/12.x..${sha}`,
        'laravel/framework@12.x..4fa008f',
        'laravel/framework comparison 12.x..4fa008f',
    ],
    [
        'https://github.com/laravel/framework/Pull/42',
        'laravel/framework#42',
        'laravel/framework pull request #42',
    ],
    [
        'https://github.com/laravel/framework/COMMIT/4fa008f',
        'laravel/framework@4fa008f',
        'laravel/framework commit 4fa008f',
    ],
    [
        'https://github.com/laravel/framework/releases/tag/v12.1.0',
        'laravel/framework@v12.1.0',
        'laravel/framework release v12.1.0',
    ],
    [
        'https://github.com/laravel/framework/releases/tag/packages/queue-1.0',
        'laravel/framework@packages/queue-1.0',
        'laravel/framework release packages/queue-1.0',
    ],
    [
        'https://github.com/laravel/framework/discussions/42',
        'laravel/framework#42',
        'laravel/framework discussion #42',
    ],
    [
        'https://github.com/laravel/framework/issues/42#issuecomment-1',
        'laravel/framework#42 (comment)',
        'laravel/framework comment on issue #42',
    ],
    [
        'https://github.com/laravel/framework/pull/42#discussion_r123',
        'laravel/framework#42 (comment)',
        'laravel/framework comment on pull request #42',
    ],
    [
        'https://github.com/laravel/framework/pull/42#pullrequestreview-7',
        'laravel/framework#42 (review)',
        'laravel/framework review on pull request #42',
    ],
    [
        'https://github.com/laravel/framework/discussions/42#discussioncomment-9',
        'laravel/framework#42 (comment)',
        'laravel/framework comment on discussion #42',
    ],
    ['https://github.com/taylorotwell', 'taylorotwell', 'taylorotwell'],
    ['https://github.com/orgs/laravel', 'laravel', 'laravel'],
    [
        'https://github.com/dev-null-sh/dev-null.sh/blob/production/app/Services/TheAlgorithm.php',
        'dev-null-sh/dev-null.sh:TheAlgorithm.php',
        'dev-null-sh/dev-null.sh file app/Services/TheAlgorithm.php',
    ],
    [
        'https://github.com/laravel/framework/blob/12.x/README.md#L10',
        'laravel/framework:README.md:10',
        'laravel/framework file README.md, line 10',
    ],
    [
        `https://github.com/laravel/framework/blob/${sha}/src/Illuminate/Support/Str.php#L10-L20`,
        'laravel/framework:Str.php:10-20',
        'laravel/framework file src/Illuminate/Support/Str.php, lines 10 to 20',
    ],
    [
        'https://github.com/laravel/framework/blob/12.x/docs/read%20me.md',
        'laravel/framework:read me.md',
        'laravel/framework file docs/read me.md',
    ],
    [
        'https://github.com/laravel/framework/tree/12.x/src/Illuminate',
        'laravel/framework:Illuminate/',
        'laravel/framework folder src/Illuminate',
    ],
]) {
    test(`shows a GitHub link as GitHub writes it: ${url}`, () => {
        const text = `see ${url}.`;
        const markup = renderToStaticMarkup(
            <LinkifiedText text={text} mentions={null} />,
        );

        assert.ok(markup.includes(`href="${url}"`));
        assert.ok(markup.includes(`title="${url}"`));
        assert.ok(markup.includes(`aria-label="${description} on GitHub"`));
        assert.equal(markup.replace(/<[^>]+>/g, ''), `see ${url}${label}.`);
        assert.equal(
            markup
                .replace(/<span aria-hidden="true"[^>]*>.*?<\/span><\/a>/g, '')
                .replace(/<[^>]+>/g, ''),
            text,
        );
    });
}

for (const link of [
    'https://github.com/laravel/',
    'https://github.com/laravel?tab=repositories',
    'https://github.com/laravel#top',
    'https://github.com/-laravel',
    'https://github.com/orgs',
    'https://github.com/orgs/laravel/people',
    'https://github.com/orgs/-laravel',
    'https://github.com/laravel/framework/',
    'https://github.com/laravel/framework/blob/12.x',
    'https://github.com/laravel/framework/blob/12.x/README.md/',
    'https://github.com/laravel/framework/blob/12.x/README.md#readme',
    'https://github.com/laravel/framework/blob/12.x/README.md#L0',
    'https://github.com/laravel/framework/tree/12.x',
    'https://github.com/laravel/framework/tree/12.x/src#L10',
    'https://github.com/laravel/framework/releases',
    'https://github.com/laravel/framework/releases/tag',
    'https://github.com/laravel/framework/releases/tag/v12.1.0/',
    'https://github.com/laravel/framework/releases/latest',
    'https://github.com/laravel/framework/commit/4fa00',
    'https://github.com/laravel/framework/commit/4fa008g',
    'https://github.com/laravel/framework/commit/4fa008f/',
    `https://github.com/laravel/framework/commit/${sha}0`,
    'https://github.com/laravel/framework/commit/4fa008f#diff-1',
    'https://github.com/laravel/framework/compare/12.x',
    'https://github.com/laravel/framework/compare/a...b...c',
    'https://github.com/laravel/framework/compare/...b',
    'https://user:secret@github.com/laravel/framework',
    'https://user@github.com/laravel/framework/issues/42',
    'https://github.com:8443/laravel/framework',
    'https://github.com/laravel/framework/pull/42/files',
    'https://github.com/laravel/framework/pull/42/',
    'https://github.com/laravel/framework/issues/0',
    'https://github.com/laravel/framework/issues/042',
    'https://github.com/laravel/framework/issues',
    'https://github.com/laravel/framework/issues/42#top',
    'https://github.com/laravel/framework/issues/42#issuecomment-',
    'https://github.com/laravel/framework/issues/42?page=2',
    'https://github.com/laravel/framework/discussions/0',
    'https://github.com/laravel/framework/discussions',
    'https://github.com/laravel/framework/constructor/42',
    'https://github.com/laravel/framework/constructor/42#x',
    'https://github.com/laravel/framework/__proto__/42#x',
    'https://github.com/laravel/framework/toString/42',
    'https://github.com/laravel/framework?tab=readme',
    'https://github.com/laravel/framework#readme',
    'https://github.com/features/actions',
    'https://github.com/features',
    'https://github.com/dashboard',
    'https://github.com/copilot',
    'https://github.com/readme',
    'https://github.com/signup',
    'https://github.com/blog',
    'https://github.com/education',
    'https://github.com/enterprises',
    'https://github.com/mobile',
    'https://github.com/settings/profile',
    'https://gist.github.com/laravel/framework',
    'https://notgithub.com/laravel/framework',
]) {
    test(`keeps other GitHub links as URLs: ${link}`, () => {
        const markup = renderToStaticMarkup(
            <LinkifiedText text={link} mentions={null} />,
        );

        assert.ok(!markup.includes('aria-label'));
        assert.ok(markup.includes(`href="${link}"`));
    });
}

test('shows a hex color with a swatch of it', () => {
    const html = renderToStaticMarkup(
        <LinkifiedText text="Brand orange is #ff8a4c." mentions={null} />,
    );

    assert.ok(html.includes('data-test="color"'));
    assert.ok(html.includes('background-color:#ff8a4c'));
    assert.equal(html.replace(/<[^>]*>/g, ''), 'Brand orange is #ff8a4c.');
});

test('shows no swatch for a color in code when given a whole body', () => {
    const html = renderToStaticMarkup(
        <LinkifiedText
            text={'see `#fff` and\n```\n#abc\n```\nthen #ff8a4c'}
            mentions={null}
        />,
    );

    assert.equal(html.match(/data-test="color"/g)?.length, 1);
    assert.ok(html.includes('background-color:#ff8a4c'));
});

test('shows swatches next to links, mentions and wordmarks', () => {
    const html = renderToStaticMarkup(
        <LinkifiedText
            text="#fff on /dev/null by @Alice at https://example.com/#abc, then #abc"
            mentions={{ alice: '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b' }}
        />,
    );

    assert.equal(html.match(/data-test="color"/g)?.length, 2);
    assert.ok(html.includes('data-test="mention"'));
    assert.ok(html.includes('href="https://example.com/#abc"'));
});

test('shows a swatch for a color that starts a quoted line at the start of a whole body', () => {
    const colors = (text: string) =>
        renderToStaticMarkup(
            <LinkifiedText text={text} mentions={null} startsText />,
        ).match(/data-test="color"/g)?.length ?? 0;

    assert.equal(colors('>#fff'), 1);
    assert.equal(colors('line\n>#fff'), 1);
    assert.equal(colors('>>#fff'), 0);
    assert.equal(colors('`x`>#fff'), 0);
});

test('styles every standalone brand mention in UI copy without changing its text', () => {
    const text = 'Welcome to /dev/null. /dev/null was updated.';
    const html = renderToStaticMarkup(<WordmarkText>{text}</WordmarkText>);

    assert.equal(html.replace(/<[^>]*>/g, ''), text);
    assert.equal(html.match(/-webkit-text-stroke/g)?.length, 2);
    assert.equal(html.match(/whitespace-nowrap/g)?.length, 2);
});

test('leaves paths, URLs, and longer names unchanged in UI copy', () => {
    const text =
        'https://example.com/dev/null /dev/nullish /dev/null/file x/dev/null /dev/null-é';

    assert.equal(
        renderToStaticMarkup(<WordmarkText>{text}</WordmarkText>),
        text,
    );
});

test('leaves literal code plain while styling surrounding brand mentions', () => {
    const text =
        '/dev/null tip: Use `cat > /dev/null` for output.\n```sh\ncat > /dev/null\n```\nBack to /dev/null.';
    const html = renderToStaticMarkup(<WordmarkText>{text}</WordmarkText>);

    assert.equal(html.match(/-webkit-text-stroke/g)?.length, 2);
    assert.ok(html.includes('`cat &gt; /dev/null`'));
    assert.ok(html.includes('```sh\ncat &gt; /dev/null\n```'));
});

for (const kind of ['direct', 'org'] as const) {
    for (const outgoing of [false, true]) {
        test(`keeps fenced code plain in ${outgoing ? 'outgoing' : 'incoming'} ${kind} previews`, () => {
            const user: User = {
                id: 'viewer',
                name: 'Alex Morgan',
                username: 'alex',
                avatar_url: null,
                bio: null,
                company: null,
                location: null,
                website: null,
                lane: 0,
                timezone: null,
                show_local_time: false,
                has_sponsors_listing: false,
                has_github_account: true,
                created_at: '2026-10-23T10:00:00Z',
                updated_at: '2026-10-23T10:00:00Z',
            };
            const partner = {
                ...user,
                id: 'sender',
                name: 'Sam Rivera',
                username: 'sam',
            };
            const sender = outgoing ? user : partner;
            const body = '```sh\ncat > /dev/null\n```\nBack to /dev/null.';
            const prefix = outgoing ? 'you: ' : kind === 'org' ? 'Sam: ' : '';
            const html = renderPage(
                <ConversationList
                    user={user}
                    empty={null}
                    conversations={[
                        {
                            id: 'conversation',
                            kind,
                            name: 'Developers',
                            partner: kind === 'direct' ? partner : null,
                            members: [{ user: partner, role: null }],
                            last_message: {
                                id: 1,
                                user_id: sender.id,
                                user: sender,
                                client_id: 'message',
                                body,
                                created_at: user.created_at,
                            },
                            last_message_at: null,
                            ahead: 0,
                            partner_read_through_id: 0,
                            partner_read_at: null,
                            is_archived: false,
                        },
                    ]}
                />,
            );

            assert.equal(html.match(/-webkit-text-stroke/g)?.length, 1);
            assert.ok(html.includes('cat &gt; /dev/null\n```'));
            assert.ok(
                html.includes(`title="${prefix}${body.replace('>', '&gt;')}"`),
            );
        });
    }
}

test('gives the text on each side of a color its own key', () => {
    const element = LinkifiedText({
        text: 'Brand #ff8a4c, white',
        mentions: {},
    }) as ReactElement<{ children: unknown }>;
    const parts = [element.props.children].flat(Infinity) as ReactElement[];
    const keys = parts.map((part) => part.key);

    assert.equal(parts.length, 3);
    assert.equal(new Set(keys).size, keys.length, keys.join(', '));
});
