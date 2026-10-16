import emojiShortcodes from '@/lib/emoji-shortcodes.json?raw';

export type PickableEmoji = {
    name: string;
    emoji: string;
};

const popularNames = [
    '+1',
    'heart',
    'joy',
    'fire',
    'rocket',
    'tada',
    'eyes',
    '100',
    'pray',
    'thinking',
    'sweat_smile',
    'clap',
    'sparkles',
    'white_check_mark',
    'bug',
    'coffee',
];

const shortcodeEmoji = JSON.parse(emojiShortcodes) as Record<string, string>;

const seenEmoji = new Set<string>();

/**
 * Every emoji once, under the first of its shortcodes, so aliases like :+1: and :thumbsup: share one button.
 */
const pickableEmoji: PickableEmoji[] = Object.entries(shortcodeEmoji).flatMap(
    ([name, emoji]) => {
        if (seenEmoji.has(emoji)) {
            return [];
        }

        seenEmoji.add(emoji);

        return [{ name, emoji }];
    },
);

export const popularEmoji: PickableEmoji[] = popularNames.flatMap((name) =>
    shortcodeEmoji[name] ? [{ name, emoji: shortcodeEmoji[name] }] : [],
);

/**
 * Matches the search against every shortcode, aliases included, so :thumbsup finds 👍 too.
 */
export function searchEmoji(query: string): PickableEmoji[] {
    const term = query.trim().replace(/^:|:$/g, '').toLowerCase();

    if (term === '') {
        return pickableEmoji;
    }

    const matching = new Set(
        Object.entries(shortcodeEmoji)
            .filter(([name]) => name.includes(term))
            .map(([, emoji]) => emoji),
    );

    return pickableEmoji.filter(({ emoji }) => matching.has(emoji));
}
