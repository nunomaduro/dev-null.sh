/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import type { ReactElement, ReactNode } from 'react';
import { test, vi } from 'vite-plus/test';
import type { Commit } from '../../resources/js/types';

const image = new File(['restored'], 'draft.png', { type: 'image/png' });
const updateImages = vi.fn();
const drop = vi.fn();
const user = { id: 'viewer', username: 'viewer' };
const draft = {
    body: 'A restored quote',
    images: [image],
    descriptions: ['A restored screenshot'],
    isRestored: true,
    updateBody: vi.fn(),
    updateImages,
    drop,
};

vi.doMock('react', () => ({
    ...React,
    useMemo: (factory: () => unknown) => factory(),
    useSyncExternalStore: (_subscribe: unknown, snapshot: () => unknown) =>
        snapshot(),
    useRef: (initial: unknown) => ({ current: initial }),
    useState: (initial: unknown) => [
        initial === false ? true : initial,
        vi.fn(),
    ],
}));
vi.doMock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    usePage: () => ({ props: { auth: { user } } }),
}));
vi.doMock('../../resources/js/hooks/use-user', () => ({
    useUser: () => ({ user }),
}));
vi.doMock('../../resources/js/hooks/use-stash', () => ({
    useStash: () => draft,
    useHasStash: () => true,
    moveCaretToEnd: vi.fn(),
}));

const { Form } = await import('@inertiajs/react');
const { CommitQuoteButton } =
    await import('../../resources/js/components/commit-quote-button');
const { ImagePickerButton, ImagePickerPreviews } =
    await import('../../resources/js/components/commit-image-picker');
const { MentionTextarea } =
    await import('../../resources/js/components/mention-textarea');
const { StashLabel } =
    await import('../../resources/js/components/stash-label');

function elements(node: ReactNode): ReactElement<Record<string, unknown>>[] {
    if (!React.isValidElement(node)) {
        return [];
    }

    const element = node as ReactElement<Record<string, unknown>>;

    return [
        element,
        ...React.Children.toArray(element.props.children as ReactNode).flatMap(
            elements,
        ),
    ];
}

test('quotes submit restored images and clear the complete draft only on success or discard', () => {
    draft.body = 'ghp_' + 'Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4zABCD';
    const commit = {
        id: 'quoted',
        user_id: 'author',
        user: { username: 'author' },
        status: 'approved',
    } as Commit;
    const button = CommitQuoteButton({ commit })!;
    const dialog = React.Children.toArray(
        button.props.children,
    )[1] as ReactElement<{
        children: ReactElement<{ commit: Commit; onClose: () => void }>;
    }>;
    const composer = dialog.props.children;
    const renderComposer = composer.type as (
        props: typeof composer.props,
    ) => ReactElement;
    const form = elements(renderComposer(composer.props)).find(
        (element) => element.type === Form,
    )!;
    const transform = form.props.transform as (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;

    assert.deepEqual(
        transform({ body: draft.body, quoted_commit_id: commit.id }),
        {
            body: draft.body,
            quoted_commit_id: commit.id,
            images: [image],
            image_descriptions: ['A restored screenshot'],
        },
    );

    const renderFields = form.props.children as (props: {
        processing: boolean;
        errors: Record<string, string>;
    }) => ReactNode;
    const fields = elements(renderFields({ processing: false, errors: {} }));
    const beforeSubmit = form.props.onBefore as () => boolean;
    const warning = fields.find(
        (element) => (element.props.findings as unknown[] | undefined)?.length,
    )!;
    assert.equal(beforeSubmit(), false);
    assert.equal(drop.mock.calls.length, 0);
    (warning.props.onApprove as () => void)();
    assert.equal(beforeSubmit(), true);
    assert.equal(beforeSubmit(), false);

    const previews = fields.find(
        (element) => element.type === ImagePickerPreviews,
    )!;
    assert.deepEqual(previews.props.images, [image]);

    const picker = fields.find(
        (element) => element.type === ImagePickerButton,
    )!;
    assert.equal(picker.props.count, 1);
    const added = new File(['new'], 'new.png', { type: 'image/png' });
    (picker.props.onPick as (files: File[]) => void)([added]);
    assert.deepEqual(updateImages.mock.lastCall, [
        [image, added],
        ['A restored screenshot', ''],
    ]);

    const dropzone = elements(renderComposer(composer.props)).find(
        (element) => element.props.onDragEnter !== undefined,
    )!;
    const dropped = new File(['dropped'], 'dropped.png', {
        type: 'image/png',
    });
    (dropzone.props.onDrop as (event: unknown) => void)({
        dataTransfer: { types: ['Files'], files: [dropped] },
        preventDefault: vi.fn(),
    });
    assert.deepEqual(updateImages.mock.lastCall, [
        [image, dropped],
        ['A restored screenshot', ''],
    ]);

    const onSuccess = form.props.onSuccess as (page: {
        flash: { toast?: { type: 'error'; message: string } };
    }) => void;
    updateImages.mockClear();
    onSuccess({
        flash: { toast: { type: 'error', message: 'Slow down' } },
    });
    assert.equal(updateImages.mock.calls.length, 0);
    assert.equal(drop.mock.calls.length, 0);

    onSuccess({ flash: {} });
    assert.deepEqual(updateImages.mock.lastCall, [[], []]);
    assert.equal(drop.mock.calls.length, 1);

    vi.stubGlobal('document', { getElementById: () => null });
    try {
        const stash = fields.find((element) => element.type === StashLabel)!;
        updateImages.mockClear();
        (stash.props.onDrop as () => void)();
        assert.deepEqual(updateImages.mock.lastCall, [[], []]);
        assert.equal(drop.mock.calls.length, 2);
    } finally {
        vi.unstubAllGlobals();
    }
});

test('passes slash command context to the quote composer textarea', () => {
    const commit = {
        id: 'quoted',
        user_id: 'author',
        user: { username: 'author' },
        status: 'approved',
    } as Commit;
    const button = CommitQuoteButton({ commit })!;
    const dialog = React.Children.toArray(
        button.props.children,
    )[1] as ReactElement<{
        children: ReactElement<{ commit: Commit; onClose: () => void }>;
    }>;
    const composer = dialog.props.children;
    const renderComposer = composer.type as (
        props: typeof composer.props,
    ) => ReactElement;
    const form = elements(renderComposer(composer.props)).find(
        (element) => element.type === Form,
    )!;
    const renderFields = form.props.children as (props: {
        processing: boolean;
        errors: Record<string, string>;
    }) => ReactNode;
    const fields = elements(renderFields({ processing: false, errors: {} }));
    const textarea = fields.find(
        (element) => element.type === MentionTextarea,
    )!;

    assert.deepEqual(textarea.props.commandContext, {
        surface: 'quote',
        hasPoll: false,
        imageCount: 1,
        isScheduling: false,
        processing: false,
    });
    assert.equal(textarea.props.onPoll, undefined);
    assert.equal(typeof textarea.props.onImage, 'function');
});
