/// <reference types="node" />

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createElement, useState } from 'react';
import { renderToString } from 'react-dom/server';
import { useSecretWarning } from '../../resources/js/hooks/use-secret-warning';
import { loadSecretScan } from '../../resources/js/lib/sensitive-text';

const token = 'ghp_' + 'Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4zABCD';
type Warning = ReturnType<typeof useSecretWarning>;
type Step = {
    body: string;
    check: (warning: Warning) => void;
};

function run(steps: Step[]): void {
    function Scenario() {
        const [step, setStep] = useState(0);
        const warning = useSecretWarning(
            steps[Math.min(step, steps.length - 1)].body,
        );

        if (step < steps.length) {
            steps[step].check(warning);
            setStep(step + 1);
        }

        return null;
    }

    renderToString(createElement(Scenario));
}

void test('holds back a submission until the scanner has loaded', async () => {
    run([
        {
            body: 'Shipped before the scanner arrived',
            check: (warning) => assert.equal(warning.beforeSubmit(), false),
        },
    ]);

    await loadSecretScan();
});

void test('allows ordinary text without asking for confirmation', () => {
    run([
        {
            body: 'Shipped a quote with https://example.com/release',
            check: (warning) => {
                assert.equal(warning.beforeSubmit(), true);
                assert.equal(warning.confirming, false);
            },
        },
    ]);
});

void test('requires confirmation and consumes approval for a single submission', () => {
    run([
        {
            body: token,
            check: (warning) => assert.equal(warning.beforeSubmit(), false),
        },
        {
            body: token,
            check: (warning) => {
                assert.equal(warning.confirming, true);
                warning.approve();
            },
        },
        {
            body: token,
            check: (warning) => {
                assert.equal(warning.confirming, false);
                assert.equal(warning.beforeSubmit(), true);
            },
        },
        {
            body: token,
            check: (warning) => assert.equal(warning.beforeSubmit(), false),
        },
    ]);
});

void test('keeping editing does not approve the detected secret', () => {
    run([
        {
            body: token,
            check: (warning) => assert.equal(warning.beforeSubmit(), false),
        },
        { body: token, check: (warning) => warning.setConfirming(false) },
        {
            body: token,
            check: (warning) => {
                assert.equal(warning.confirming, false);
                assert.equal(warning.beforeSubmit(), false);
            },
        },
    ]);
});
