import { useMemo, useRef, useState } from 'react';
import { useSecretScan } from '@/hooks/use-secret-scan';
import { findSecrets } from '@/lib/sensitive-text';

export function useSecretWarning(body: string) {
    const isLoaded = useSecretScan();
    const findings = useMemo(
        () => (isLoaded ? (findSecrets(body) ?? []) : []),
        [body, isLoaded],
    );
    const [confirming, setConfirming] = useState(false);
    const approvedBody = useRef<string | null>(null);

    const beforeSubmit = () => {
        // The scanner starts loading with the composer, so this only holds back a submission made before it arrives.
        if (!isLoaded) {
            return false;
        }

        if (findings.length === 0 || approvedBody.current === body) {
            approvedBody.current = null;
            return true;
        }

        setConfirming(true);
        return false;
    };

    const approve = () => {
        approvedBody.current = body;
        setConfirming(false);
    };

    return {
        findings,
        confirming,
        setConfirming,
        beforeSubmit,
        approve,
    };
}
