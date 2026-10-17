import assert from 'node:assert/strict';
import { test } from 'node:test';
import { shareProfile } from '../../resources/js/lib/share-profile.ts';

const data = {
    title: '@octocat on /dev/null',
    url: 'https://devnull.sh/@octocat',
};

void test('uses native sharing when available', async () => {
    const shared: unknown[] = [];
    const result = await shareProfile(
        data,
        async (payload) => {
            shared.push(payload);
        },
        async () => {
            assert.fail('clipboard should not be called');
        },
    );

    assert.equal(result, 'shared');
    assert.deepEqual(shared, [data]);
});

void test('copies the profile URL if native sharing is unavailable', async () => {
    const copied: string[] = [];
    const result = await shareProfile(data, undefined, async (url) => {
        copied.push(url);

        return true;
    });

    assert.equal(result, 'copied');
    assert.deepEqual(copied, [data.url]);
});

void test('does not copy when the share sheet is dismissed', async () => {
    const result = await shareProfile(
        data,
        async () => {
            throw new DOMException('Cancelled', 'AbortError');
        },
        async () => {
            assert.fail('clipboard should not be called');
        },
    );

    assert.equal(result, 'cancelled');
});

void test('copies the URL if native sharing fails', async () => {
    const result = await shareProfile(
        data,
        async () => {
            throw new Error('Native share unavailable');
        },
        async (url) => {
            assert.equal(url, data.url);

            return true;
        },
    );

    assert.equal(result, 'copied');
});

void test('reports clipboard failure', async () => {
    assert.equal(
        await shareProfile(data, undefined, async () => false),
        'failed',
    );
    assert.equal(
        await shareProfile(data, undefined, async () => {
            throw new Error('Permission denied');
        }),
        'failed',
    );
});
