export type TextSelection = Pick<
    HTMLTextAreaElement,
    'selectionStart' | 'selectionEnd'
>;

/**
 * Inserts the text over the selection, or at the end without one. Null when the result would be longer than
 * `maxLength` characters, counted like the composer's counter.
 */
export function insertAtSelection(
    value: string,
    selection: TextSelection | null,
    text: string,
    maxLength: number,
): { value: string; caret: number } | null {
    const start = selection?.selectionStart ?? value.length;
    const end = selection?.selectionEnd ?? value.length;
    const next = value.slice(0, start) + text + value.slice(end);

    if (Array.from(next).length > maxLength) {
        return null;
    }

    return { value: next, caret: start + text.length };
}
