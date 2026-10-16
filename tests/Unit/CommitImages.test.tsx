/// <reference types="node" />
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { ReactElement, SyntheticEvent } from 'react';
import { CommitImages } from '../../resources/js/components/commit-images';
import type { Commit } from '../../resources/js/types/commit';

const commit: Commit = {
    id: 'commit-1',
    user_id: 'user-1',
    parent_id: null,
    hot_score: 41484.833333,
    quoted_commit_id: null,
    body: 'Photo from the build',
    body_segments: [],
    preview_segments: null,
    status: 'approved',
    created_at: '2026-10-16T10:00:00Z',
    updated_at: '2026-10-16T10:00:00Z',
    stars_count: 0,
    impressions_count: 0,
    impression_token: null,
    impression_token_expires_at: null,
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
            id: 'image-1',
            url: '/images/missing.webp',
            width: 100,
            height: 100,
            description: null,
        },
    ],
};

function renderedImage() {
    const gallery = CommitImages({ commit });
    const link = gallery!.props.children[0];

    return link.props.children as ReactElement<{
        src: string;
        onError: (event: SyntheticEvent<HTMLImageElement>) => void;
    }>;
}

void test('keeps the uploaded image until it fails to load', () => {
    assert.equal(renderedImage().props.src, '/images/missing.webp');
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
        classList: {
            replace: (from: string, to: string) => {
                assert.equal(from, 'object-cover');
                assert.equal(to, 'object-contain');
            },
        },
    };
    const event = {
        currentTarget: target,
    } as unknown as SyntheticEvent<HTMLImageElement>;
    const { onError } = renderedImage().props;

    onError(event);
    assert.equal(source, '/favicon.svg');

    onError(event);
    assert.equal(replacements, 1);
});

void test('renders nothing when there are no uploaded images', () => {
    assert.equal(CommitImages({ commit: { ...commit, images: [] } }), null);
});
