import { useEffect, useSyncExternalStore } from 'react';
import {
    isSecretScanLoaded,
    loadSecretScan,
    onSecretScanLoaded,
} from '@/lib/sensitive-text';

/**
 * Loads the secret scanner for a composer, and renders it again once the scanner is there.
 */
export function useSecretScan(): boolean {
    useEffect(() => {
        void loadSecretScan();
    }, []);

    return useSyncExternalStore(
        onSecretScanLoaded,
        isSecretScanLoaded,
        isSecretScanLoaded,
    );
}
