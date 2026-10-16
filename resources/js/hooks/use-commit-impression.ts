import { useEffect, useState, type RefCallback } from 'react';
import {
    commitImpressionsFor,
    endCommitImpressionSession,
} from '@/lib/commit-impressions';

export function useCommitImpressionSession(viewerId: string | null): void {
    useEffect(() => {
        commitImpressionsFor(viewerId);
        return () => endCommitImpressionSession(viewerId);
    }, [viewerId]);
}

export function useCommitImpression({
    commitId,
    token,
    expiresAt,
    viewerId,
    eligible,
}: {
    commitId: string;
    token: string | null;
    expiresAt: number | null;
    viewerId: string | null;
    eligible: boolean;
}): RefCallback<HTMLElement> {
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        if (
            !eligible ||
            !token ||
            expiresAt === null ||
            viewerId === null ||
            target === null
        ) {
            return;
        }

        return commitImpressionsFor(viewerId)?.observe(
            commitId,
            target,
            token,
            expiresAt,
        );
    }, [commitId, token, expiresAt, viewerId, eligible, target]);

    return setTarget;
}
