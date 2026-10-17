/// <reference types="node" />
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { App } from '@inertiajs/react';
import type { ComponentProps, ReactElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { CommitBody } from '../../resources/js/components/commit-body';
import {
    LinkifiedText,
    typedLinks,
} from '../../resources/js/components/linkified-text';

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

void test('keeps stable mention links alongside shortened URLs and their full copyable text', () => {
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

void test('leaves mentions without captured identities as plain text', () => {
    const html = renderToStaticMarkup(
        <LinkifiedText text="Thanks @original!" mentions={null} />,
    );

    assert.equal(html, 'Thanks @original!');
});

void test('keeps captured mention identities in regular bodies alongside highlighted code', () => {
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

void test('finds the full preview URL outside inline and fenced code', () => {
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

void test('shortens the displayed preview link while retaining the full URL', () => {
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

void test('keeps every distinct card URL in order without joining prose across code', () => {
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
    void test(`ignores card URLs in unfinished code: ${body}`, () => {
        assert.deepEqual(typedLinks(body), []);
    });
}

void test('renders /dev/null as the wordmark while keeping its text', () => {
    const text = 'piped to /dev/null, then /dev/null. (/dev/null)';
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} />,
    );

    assert.equal(markup.match(/title="\/dev\/null"/g)?.length, 3);
    assert.equal(markup.replace(/<[^>]+>/g, ''), text);
});

void test('leaves /dev/null inside longer paths, words, URLs and usernames as text', () => {
    const text =
        '/dev/nullable /dev/null/log a/dev/null /dev/null_1 /DEV/NULL https://example.com/dev/null @devnull';
    const markup = renderToStaticMarkup(
        <LinkifiedText text={text} mentions={null} />,
    );

    assert.ok(!markup.includes('title="/dev/null"'));
});

const commitUrl =
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8';

void test('shows a dev/null commit link as its author and short hash', () => {
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

for (const link of [
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8/photos/1',
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8?tab=replies',
    'https://example.com/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8',
    'https://dev-null.sh/@Hacksore',
]) {
    void test(`keeps other links as URLs: ${link}`, () => {
        const markup = renderToStaticMarkup(
            <LinkifiedText text={link} mentions={null} />,
        );

        assert.ok(!markup.includes('aria-label'));
        assert.ok(markup.includes(`href="${link}"`));
    });
}
