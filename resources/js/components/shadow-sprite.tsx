import { cn } from '@/lib/utils';
import type { ShadowSkin } from '@/types';

const SHADOW_FORMS = [
    ['   ·   ', '   ¤   ', '       '],
    ['  .-.  ', ' ( ¤ ) ', '  /_\\  '],
    ['  /\\   ', ' < ¤ > ', '  /_\\  '],
    [' /^^\\  ', ' | ¤ | ', ' \\__/  '],
    [' /|_|\\ ', ' <|¤|> ', ' /|_|\\ '],
    [' /===\\ ', ' <[¤]> ', ' /===\\ '],
];

const SKIN_COLORS: Record<ShadowSkin, string> = {
    'classic-green': 'text-emerald-600 dark:text-emerald-400',
    'amber-crt': 'text-amber-600 dark:text-amber-400',
    matrix: 'text-green-600 dark:text-green-400',
    'cyber-cyan': 'text-cyan-600 dark:text-cyan-400',
    'kernel-red': 'text-rose-600 dark:text-rose-400',
};

export function ShadowSprite({
    level,
    glyph,
    skin,
    compact = false,
}: {
    level: number;
    glyph: string;
    skin: ShadowSkin;
    compact?: boolean;
}) {
    const appearance = SKIN_COLORS[skin];
    const stage = [100, 70, 45, 25, 10].filter(
        (milestone) => level >= milestone,
    ).length;
    const symbol = glyph.slice(0, 2);

    if (compact) {
        return (
            <span
                className={cn(
                    'inline-flex size-5 items-center justify-center rounded border border-current/30 bg-muted/40 font-mono text-[11px] leading-none',
                    appearance,
                )}
                aria-hidden="true"
            >
                {symbol}
            </span>
        );
    }

    return (
        <pre
            role="img"
            aria-label={`Shadow evolution stage ${stage + 1}`}
            className={cn(
                'font-mono text-sm leading-4 font-bold select-none',
                appearance,
            )}
        >
            {SHADOW_FORMS[stage].join('\n').replace('¤', symbol)}
        </pre>
    );
}
