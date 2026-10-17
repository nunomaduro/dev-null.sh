import { mapTypedProse } from '@/lib/code';
import { characterCount } from '@/lib/utils';

// Keep in sync with App\Models\Commit.
export const minBodyLength = 1;
export const maxBodyLength = 4000;
export const previewLength = 280;
export const previewLines = 8;

/**
 * The body as it will be saved: trimmed, with runs of blank lines in the prose collapsed into one. Keep in sync with
 * App\Services\CommitBodies::normalize().
 */
function normalizeBody(body: string): string {
    return mapTypedProse(body.replace(/\r\n/g, '\n'), (prose) =>
        prose.replace(/(?:[ \t]*\n){3,}/g, '\n\n'),
    ).trim();
}

export function isFoldedInLists(body: string): boolean {
    const normalized = normalizeBody(body);

    return (
        characterCount(normalized) > previewLength ||
        normalized.split('\n').length > previewLines
    );
}
