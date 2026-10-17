/// <reference types="node" />
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    CommitImages,
    replaceFailedImage,
} from '../../resources/js/components/commit-images';
import { scrambleHash } from '../../resources/js/hooks/use-scrambled-hash';
import type { Commit } from '../../resources/js/types/commit';

const commit: Commit = {
    id: 'commit-1',
    user_id: 'user-1',
    parent_id: null,
    hot_score: 41484.833333,
    quoted_commit_id: null,
    body: 'Photo from the build',
    mentions: {},
    body_segments: [],
    preview_segments: null,
    status: 'approved',
    created_at: '2026-10-16T10:00:00Z',
    updated_at: '2026-10-16T10:00:00Z',
    stars_count: 0,
    pasteis_count: 0,
    starred: false,
    user: {
        id: 'user-1',
        username: 'octocat',
        name: 'Octocat',
        avatar_url: null,
        bio: null,
        location: null,
        website: null,
        company: null,
        has_sponsors_listing: false,
        has_github_account: true,
        lane: 0,
        created_at: '2026-10-16T10:00:00Z',
        updated_at: '2026-10-16T10:00:00Z',
    },
    images: [
        {
            id: '9f3a6c1e-4b2d-4e8a-9c1f-2d7e5b8a0c3d',
            url: '/images/missing.webp',
            width: 100,
            height: 100,
            description: null,
        },
    ],
};

void test('keeps the uploaded image until it fails to load', () => {
    assert.ok(
        renderToStaticMarkup(<CommitImages commit={commit} />).includes(
            'src="/images/missing.webp"',
        ),
    );
});

void test('shows the short image hash instead of the alt text while the image loads', () => {
    const markup = renderToStaticMarkup(<CommitImages commit={commit} />);
    const placeholder = markup.match(
        /<div aria-hidden="true" data-test="commit-image-loading".*?<\/div><\/div><\/div>/,
    );

    assert.ok(placeholder);
    assert.equal(placeholder[0].replace(/<[^>]+>/g, ''), 'commitb8a0c3d');
});

void test('scrambles each hash differently, locking it in from left to right, then rolls again', () => {
    const lockedAt = (frame: number) =>
        scrambleHash('9f3a6c1', frame)
            .filter(({ isLocked }) => isLocked)
            .map(({ character }) => character)
            .join('');

    assert.equal(lockedAt(0), '');
    assert.equal(lockedAt(3), '9');
    assert.equal(lockedAt(9), '9f3');
    assert.equal(lockedAt(21), '9f3a6c1');
    assert.equal(lockedAt(30), '9f3a6c1');
    assert.equal(lockedAt(31), '');
    assert.notEqual(
        scrambleHash('9f3a6c1', 4)
            .slice(1)
            .map(({ character }) => character)
            .join(''),
        scrambleHash('b8a0c3d', 4)
            .slice(1)
            .map(({ character }) => character)
            .join(''),
    );
    assert.equal(
        scrambleHash('9f3a6c1', null)
            .map(({ character }) => character)
            .join(''),
        '9f3a6c1',
    );
});

void test('replaces a failed image with the site logo without retrying the fallback', () => {
    let replacements = 0;
    let source = '/images/missing.webp';
    const target = {
        getAttribute: () => source,
        set src(value: string) {
            source = value;
            replacements++;
        },
        dataset: {} as DOMStringMap,
    };
    const image = target as unknown as HTMLImageElement;

    replaceFailedImage(image);
    assert.equal(source, '/favicon.svg');
    assert.equal(image.dataset.fallback, '');

    replaceFailedImage(image);
    assert.equal(replacements, 1);
});

void test('renders nothing when there are no uploaded images', () => {
    assert.equal(CommitImages({ commit: { ...commit, images: [] } }), null);
});
