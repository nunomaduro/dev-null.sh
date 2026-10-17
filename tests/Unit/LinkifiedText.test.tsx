/// <reference types="node" />
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    typedLinks,
    LinkifiedText,
} from '../../resources/js/components/linkified-text';

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
    const markup = renderToStaticMarkup(<LinkifiedText text={url} />);

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
    const markup = renderToStaticMarkup(<LinkifiedText text={text} />);

    assert.equal(markup.match(/title="\/dev\/null"/g)?.length, 3);
    assert.equal(markup.replace(/<[^>]+>/g, ''), text);
});

void test('leaves /dev/null inside longer paths, words, URLs and usernames as text', () => {
    const text =
        '/dev/nullable /dev/null/log a/dev/null /dev/null_1 /DEV/NULL https://example.com/dev/null @devnull';
    const markup = renderToStaticMarkup(<LinkifiedText text={text} />);

    assert.ok(!markup.includes('title="/dev/null"'));
});

const commitUrl =
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8';

void test('shows a dev/null commit link as its author and short hash', () => {
    const text = `same root cause as ${commitUrl}.`;
    const markup = renderToStaticMarkup(<LinkifiedText text={text} />);

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
        const markup = renderToStaticMarkup(<LinkifiedText text={link} />);

        assert.ok(!markup.includes('aria-label'));
        assert.ok(markup.includes(`href="${link}"`));
    });
}
