import assert from 'node:assert/strict';
import { isValidElement } from 'react';
import type { ReactElement } from 'react';
import { test } from 'vite-plus/test';
import { RemoteLocalTime } from '../../resources/js/components/chat/remote-local-time';

test('shows time only when the partner has opted in and set a timezone', () => {
    assert.equal(
        RemoteLocalTime({
            partner: { timezone: 'Asia/Kolkata', show_local_time: false },
        }),
        null,
    );
    assert.equal(
        RemoteLocalTime({ partner: { timezone: null, show_local_time: true } }),
        null,
    );

    const result = RemoteLocalTime({
        partner: { timezone: 'Asia/Kolkata', show_local_time: true },
    });

    assert.ok(
        isValidElement<{
            title: string;
            children: ReactElement<{ timeZone: string }>;
        }>(result),
    );
    assert.equal(result.props.title, 'Asia/Kolkata');
    assert.equal(result.props.children.props.timeZone, 'Asia/Kolkata');
});
