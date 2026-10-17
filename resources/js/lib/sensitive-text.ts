import type { Secret } from '@sanity-labs/secret-scan';

type SecretScan = typeof import('@sanity-labs/secret-scan');

let secretScan: SecretScan | null = null;
let loading: Promise<void> | null = null;
const listeners = new Set<() => void>();

/**
 * The scanner's rules weigh more than the rest of the layout, and only composers need them, so they load with the
 * first composer instead of on every page.
 */
export function loadSecretScan(): Promise<void> {
    loading ??= import('@sanity-labs/secret-scan').then((module) => {
        secretScan = module;
        listeners.forEach((listener) => listener());
    });

    return loading;
}

export const isSecretScanLoaded = (): boolean => secretScan !== null;

export function onSecretScanLoaded(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/**
 * Null until the scanner has loaded, as no text can be called safe before it is checked.
 */
export function findSecrets(body: string): Secret[] | null {
    return secretScan?.scan(body) ?? null;
}

export function removeSecrets(body: string): string {
    return secretScan?.redact(body, () => '[REDACTED]') ?? body;
}

/**
 * Null until the scanner has loaded, so a draft is neither saved nor discarded before it is checked.
 */
export function stashableBody(body: string): string | null {
    const findings = findSecrets(body);

    if (findings === null) {
        return null;
    }

    return findings.length > 0 ? '' : body;
}
