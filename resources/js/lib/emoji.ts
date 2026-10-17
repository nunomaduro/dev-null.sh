import { mapTypedProse } from '@/lib/code';
import emojiShortcodes from '@/lib/emoji-shortcodes.json?raw';

const shortcodeEmoji = JSON.parse(emojiShortcodes) as Record<string, string>;

const escapeRegExp = (name: string): string =>
    name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const shortcodeNames = Object.keys(shortcodeEmoji).sort(
    (left, right) => right.length - left.length,
);

const shortcodeNamesPattern = shortcodeNames.map(escapeRegExp).join('|');

const shortcodePattern = new RegExp(
    `(?<![A-Za-z0-9_]):(${shortcodeNamesPattern}):?(?![A-Za-z0-9_])`,
    'g',
);

const caretMarker = '\u0000';

// While typing, a shortcode without its closing colon that ends at the caret is still being written (:a on its
// way to :apple), so it waits for the closing colon or for the caret to move past it.
const typedShortcodePattern = new RegExp(
    `(?<![A-Za-z0-9_]):(${shortcodeNamesPattern})(?::(?![A-Za-z0-9_])|(?![A-Za-z0-9_${caretMarker}]))`,
    'g',
);

export function emojify(text: string): string {
    return text.replace(
        shortcodePattern,
        (_, name: string) => shortcodeEmoji[name],
    );
}

export function emojifyInput(
    text: string,
    caret: number,
): { text: string; caret: number } {
    const marked = text.slice(0, caret) + caretMarker + text.slice(caret);
    const emojified = mapTypedProse(marked, (prose) =>
        prose.replace(
            typedShortcodePattern,
            (_, name: string) => shortcodeEmoji[name],
        ),
    );
    const markerIndex = emojified.indexOf(caretMarker);

    return {
        text: emojified.replace(caretMarker, ''),
        caret: markerIndex,
    };
}

export type EmojiSuggestion = {
    name: string;
    emoji: string;
};

const trailingShortcodePattern = /(?<![A-Za-z0-9_]):([A-Za-z0-9_+-]*)$/;

export function suggestEmoji(text: string): EmojiSuggestion | null {
    let typedProse = '';

    mapTypedProse(text, (prose) => (typedProse = prose));

    const match = trailingShortcodePattern.exec(typedProse);

    if (match === null || match[1] === '') {
        return null;
    }

    const partial = match[1].toLowerCase();

    let best: { name: string; emoji: string } | null = null;

    for (const [name, emoji] of Object.entries(shortcodeEmoji)) {
        if (
            name.startsWith(partial) &&
            (best === null || name.length < best.name.length)
        ) {
            best = { name, emoji };
        }
    }

    return best;
}

export function completeEmoji(text: string): string | null {
    const suggestion = suggestEmoji(text);

    if (suggestion === null) {
        return null;
    }

    return text.replace(trailingShortcodePattern, suggestion.emoji);
}
