import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('../resources/js', import.meta.url)),
        },
    },
    test: {
        include: [
            'tests/Unit/DraftStash.test.tsx',
            'tests/Unit/ComposerDraft.test.tsx',
            'tests/Unit/QuoteDraft.test.tsx',
            'tests/Unit/EmojiSearch.test.tsx',
            'tests/Unit/EmojiShortcodes.test.tsx',
            'tests/Unit/QuoteReply.test.tsx',
            'tests/Unit/SlashCommandMenu.test.tsx',
        ],
    },
});
