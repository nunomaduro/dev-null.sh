/// <reference types="node" />
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    emptyYear,
    isGitHubActivity,
} from '../../resources/js/lib/github-activity';

void test('recognizes only a whole /activity commit', () => {
    assert.ok(isGitHubActivity('/activity'));
    assert.ok(isGitHubActivity('  /Activity\n'));
    assert.ok(!isGitHubActivity('activity'));
    assert.ok(!isGitHubActivity('/activity of the week'));
});

void test('lays out an empty year from a Sunday, like the calendar on GitHub', () => {
    const days = emptyYear(new Date('2026-10-16T15:00:00Z'));

    assert.equal(days[0].date, '2025-10-12');
    assert.equal(new Date(`${days[0].date}T00:00:00Z`).getUTCDay(), 0);
    assert.equal(days.at(-1)?.date, '2026-10-16');
    assert.equal(days.length, 370);
    assert.ok(days.every((day) => day.count === 0 && day.level === 0));
});

void test('starts a year that ends on a Saturday 52 full weeks earlier', () => {
    const days = emptyYear(new Date('2026-10-17T00:00:00Z'));

    assert.equal(days[0].date, '2025-10-12');
    assert.equal(days.length, 371);
});
