/// <reference types="node" />
import assert from 'node:assert/strict';
import * as React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, test, vi } from 'vite-plus/test';

vi.doMock('react', () => ({
    ...React,
    useSyncExternalStore: (_subscribe: unknown, read: () => unknown) => read(),
}));

const { LocalTime } = await import('../../resources/js/components/local-time');

afterEach(() => vi.useRealTimers());

test('shows the time in the given timezone with its offset', () => {
    vi.useFakeTimers({ now: new Date('2026-07-01T10:06:30Z') });

    const html = renderToStaticMarkup(
        <LocalTime timeZone="Europe/Berlin" as="p" className="mt-2" />,
    );
    const expected = new Date('2026-07-01T10:06:00Z').toLocaleTimeString(
        undefined,
        { hour: 'numeric', minute: '2-digit', timeZone: 'Europe/Berlin' },
    );

    assert.ok(html.startsWith('<p class="flex items-center gap-1.5 mt-2"'));
    assert.ok(html.includes('dateTime="2026-07-01T10:06:00.000Z"'));
    assert.ok(html.includes(`${expected}</time> (UTC +02:00)`));
});

test('renders as a list item by default, for the profile details', () => {
    const html = renderToStaticMarkup(<LocalTime timeZone="UTC" />);

    assert.ok(html.startsWith('<li'));
    assert.ok(html.includes('(UTC)'));
});
