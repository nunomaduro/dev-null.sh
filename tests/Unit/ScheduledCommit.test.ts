import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import {
    localDateTime,
    scheduledTimestamp,
} from '../../resources/js/lib/scheduled-commit.ts';

const timezone = process.env.TZ;

afterEach(() => {
    if (timezone === undefined) {
        delete process.env.TZ;
    } else {
        process.env.TZ = timezone;
    }
});

await test('formats existing schedules in the viewer timezone, including a date boundary', () => {
    process.env.TZ = 'Asia/Dhaka';
    assert.equal(
        localDateTime(new Date('2026-10-20T18:05:00Z')),
        '2026-10-21T00:05',
    );
});

await test('converts a chosen local time to an explicit UTC timestamp', () => {
    for (const [zone, expected] of [
        ['Asia/Dhaka', '2026-10-20T06:30:00+00:00'],
        ['Asia/Kolkata', '2026-10-20T07:00:00+00:00'],
        ['America/New_York', '2026-10-20T16:30:00+00:00'],
    ]) {
        process.env.TZ = zone;
        assert.equal(scheduledTimestamp('2026-10-20T12:30'), expected);
    }
});

await test('rejects empty, invalid, and nonexistent daylight-saving times', () => {
    process.env.TZ = 'America/New_York';
    for (const value of ['', 'not a date', '2027-03-14T02:30']) {
        assert.equal(scheduledTimestamp(value), null);
    }
    assert.equal(
        scheduledTimestamp('2027-03-14T03:30'),
        '2027-03-14T07:30:00+00:00',
    );
});

await test('uses the browser Date choice for an ambiguous local time', () => {
    process.env.TZ = 'America/New_York';
    assert.equal(
        scheduledTimestamp('2026-11-01T01:30'),
        '2026-11-01T05:30:00+00:00',
    );
});
