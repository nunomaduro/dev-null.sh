import type { LucideIcon } from 'lucide-react';
import { Braces, ChartBar, ImagePlus } from 'lucide-react';

// Keep in sync with maxImages in resources/js/components/commit-image-picker.tsx.
const maxImages = 3;

export type SlashCommandId = 'image' | 'poll' | 'code';

export type SlashCommand = {
    id: SlashCommandId;
    label: string;
    description: string;
    aliases: string[];
    icon: LucideIcon;
};

export type ComposerSurface = {
    surface: 'commit' | 'reply' | 'quote';
    hasPoll: boolean;
    imageCount: number;
    isScheduling: boolean;
    processing: boolean;
};

export type SlashOption = {
    command: SlashCommand;
    enabled: boolean;
    reason: string | null;
};

const commands: SlashCommand[] = [
    {
        id: 'image',
        label: 'Image',
        description: 'Attach up to 3 images',
        aliases: ['img'],
        icon: ImagePlus,
    },
    {
        id: 'poll',
        label: 'Poll',
        description: 'Ask a question with up to 4 options',
        aliases: [],
        icon: ChartBar,
    },
    {
        id: 'code',
        label: 'Code',
        description: 'Insert a code block',
        aliases: [],
        icon: Braces,
    },
];

function disabledReason(
    command: SlashCommand,
    surface: ComposerSurface,
): string | null {
    if (command.id === 'image') {
        if (surface.hasPoll) {
            return 'Remove the poll first';
        }

        if (surface.imageCount >= maxImages) {
            return 'Maximum of 3 images';
        }

        return null;
    }

    if (command.id === 'poll') {
        if (surface.hasPoll) {
            return 'A poll is already added';
        }

        if (surface.imageCount > 0) {
            return 'Remove the images first';
        }

        return null;
    }

    return null;
}

/**
 * The commands a composer offers, in menu order, each with its availability or the reason it is disabled.
 */
export function availableCommands(surface: ComposerSurface): SlashOption[] {
    return commands
        .filter(
            (command) => command.id !== 'poll' || surface.surface === 'commit',
        )
        .map((command) => {
            if (surface.processing) {
                return { command, enabled: false, reason: null };
            }

            const reason = disabledReason(command, surface);

            return { command, enabled: reason === null, reason };
        });
}

export function filterCommands(
    options: SlashOption[],
    query: string,
): SlashOption[] {
    if (query === '') {
        return options;
    }

    return options.filter(
        ({ command }) =>
            command.id.startsWith(query) ||
            command.aliases.some((alias) => alias.startsWith(query)),
    );
}

export function matchCommand(query: string): SlashCommand | null {
    return (
        commands.find(
            (command) =>
                command.id === query || command.aliases.includes(query),
        ) ?? null
    );
}

/**
 * The body without the "/command" token, dropping one adjacent space.
 */
export function consumeSlashToken(
    value: string,
    start: number,
    query: string,
): string {
    const before = value.slice(0, start);
    const after = value.slice(start + query.length + 1);

    if (before.endsWith(' ')) {
        return before.slice(0, -1) + after;
    }

    if (after.startsWith(' ')) {
        return before + after.slice(1);
    }

    return before + after;
}

/**
 * The body with a fenced code block where the token was, and the caret index inside it.
 */
export function codeFenceInsertion(
    value: string,
    start: number,
    end: number,
): { value: string; caret: number } {
    const before = value.slice(0, start);
    const after = value.slice(end);
    const prefix = before === '' || before.endsWith('\n') ? '' : '\n';
    const suffix = after === '' || after.startsWith('\n') ? '' : '\n';

    return {
        value: `${before}${prefix}\`\`\`\n\n\`\`\`${suffix}${after}`,
        caret: before.length + prefix.length + 4,
    };
}
