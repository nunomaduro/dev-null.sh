import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    formatCompactCount,
    formatFullCount,
} from '../../resources/js/lib/numbers.ts';

void test('keeps counts below a thousand as they are', () => {
    assert.equal(formatCompactCount(0), '0');
    assert.equal(formatCompactCount(1), '1');
    assert.equal(formatCompactCount(999), '999');
});

void test('abbreviates thousands, millions, billions and trillions', () => {
    assert.equal(formatCompactCount(1_000), '1K');
    assert.equal(formatCompactCount(12_345), '12.3K');
    assert.equal(formatCompactCount(1_234_567), '1.2M');
    assert.equal(formatCompactCount(5_000_000_000), '5B');
    assert.equal(formatCompactCount(2_100_000_000_000), '2.1T');
});

void test('truncates so a count never reads higher than it is', () => {
    assert.equal(formatCompactCount(1_250), '1.2K');
    assert.equal(formatCompactCount(1_999), '1.9K');
    assert.equal(formatCompactCount(999_999), '999.9K');
    assert.equal(formatCompactCount(1_999_999), '1.9M');
});

void test('spells out the exact count with separators', () => {
    assert.equal(formatFullCount(999), '999');
    assert.equal(formatFullCount(1_234_567), '1,234,567');
});
