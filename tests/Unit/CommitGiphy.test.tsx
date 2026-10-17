/// <reference types="node" />
import assert from 'node:assert/strict';
import type { ReactElement, SyntheticEvent } from 'react';
import { test } from 'vite-plus/test';
import { CommitGiphy } from '../../resources/js/components/commit-giphy';
import type { CommitGif } from '../../resources/js/types/commit';

const gif: CommitGif = {
    id: 'xT9IgzoKnwFNmISR8I',
    url: 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123',
    page_url: 'https://giphy.com/gifs/deal-with-it-xT9IgzoKnwFNmISR8I',
    title: 'Deal With It GIF',
    username: 'giphy',
    width: 480,
    height: 270,
};

function renderedGiphy(overrides: Partial<CommitGif> = {}) {
    const figure = CommitGiphy({ gif: { ...gif, ...overrides } });
    const [image, attribution] = figure.props.children as [
        ReactElement<{
            src: string;
            alt: string;
            loading: string;
            referrerPolicy: string;
            onError: (event: SyntheticEvent<HTMLImageElement>) => void;
        }>,
        ReactElement<{ href: string; target: string; rel: string; children: string }>,
    ];

    return { figure, image, attribution };
}

test('hotlinks the giphy url verbatim with its query string intact', () => {
    const { image } = renderedGiphy();

    assert.equal(
        image.props.src,
        'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123',
    );
    assert.equal(image.props.alt, 'Deal With It GIF');
    assert.equal(image.props.loading, 'lazy');
    assert.equal(image.props.referrerPolicy, 'no-referrer');
});

test('falls back to a generic alt when giphy has no title', () => {
    const { image } = renderedGiphy({ title: null });

    assert.equal(image.props.alt, 'Attached GIF');
});

test('credits the giphy uploader with a link to the gif page', () => {
    const { attribution } = renderedGiphy();

    assert.equal(attribution.props.href, gif.page_url);
    assert.equal(attribution.props.target, '_blank');
    assert.equal(attribution.props.rel, 'noopener noreferrer');
    assert.equal(attribution.props.children, 'GIF by @giphy on GIPHY');
});

test('falls back to the plain giphy credit when the uploader is unknown', () => {
    const { attribution } = renderedGiphy({ username: null });

    assert.equal(attribution.props.children, 'via GIPHY');
});

test('replaces a failed gif with the site logo without retrying the fallback', () => {
    let replacements = 0;
    let source = gif.url;
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
    const { onError } = renderedGiphy().image.props;

    onError(event);
    assert.equal(source, '/favicon.svg');

    onError(event);
    assert.equal(replacements, 1);
});
