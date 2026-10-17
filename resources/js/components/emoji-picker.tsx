import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Smile } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useRef, useState } from 'react';
import { buttonVariants } from '@/components/ui/button';
import type { PickableEmoji } from '@/lib/emoji-picker';
import { popularEmoji, searchEmoji } from '@/lib/emoji-picker';
import { cn } from '@/lib/utils';

/**
 * Pressing the button keeps the focus, and so the caret, in the textarea being written, which is handed to `onPick`
 * along with the emoji. It is null when the picker was opened from the keyboard.
 */
export function EmojiPickerButton({
    disabled,
    onPick,
    className,
}: {
    disabled?: boolean;
    onPick: (emoji: string, textarea: HTMLTextAreaElement | null) => void;
    className?: string;
}) {
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    const [query, setQuery] = useState('');
    const results = query.trim() === '' ? null : searchEmoji(query);

    return (
        <Popover>
            <PopoverButton
                type="button"
                disabled={disabled}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => {
                    const active = document.activeElement;

                    textareaRef.current =
                        active instanceof HTMLTextAreaElement ? active : null;
                    setQuery('');
                }}
                aria-label="Add emoji"
                title="Add emoji"
                className={cn(
                    buttonVariants({ variant: 'ghost', size: 'icon' }),
                    'text-muted-foreground',
                    className,
                )}
                data-test="emoji-picker-button"
            >
                <Smile className="size-5" />
            </PopoverButton>

            <PopoverPanel
                anchor={{ to: 'bottom start', gap: 4, padding: 16 }}
                focus
                className="z-50 flex max-h-[min(22rem,calc(100dvh-2rem))] w-72 flex-col overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-[0_12px_32px_-12px_rgb(0_0_0/0.35)]"
                data-test="emoji-picker"
            >
                {({ close }) => {
                    const pick = (emoji: string) => {
                        const textarea = textareaRef.current;

                        close(textarea ?? undefined);
                        onPick(emoji, textarea);
                    };

                    const pickFirst = (event: KeyboardEvent) => {
                        if (event.key !== 'Enter') {
                            return;
                        }

                        event.preventDefault();

                        const first = (results ?? popularEmoji)[0];

                        if (first) {
                            pick(first.emoji);
                        }
                    };

                    return (
                        <>
                            <div className="border-b p-2">
                                <input
                                    type="search"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    onKeyDown={pickFirst}
                                    placeholder="Search emoji"
                                    aria-label="Search emoji"
                                    autoComplete="off"
                                    className="h-8 w-full rounded-md border bg-background px-2 text-base outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                                />
                            </div>

                            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2">
                                {results === null ? (
                                    <>
                                        <EmojiSection
                                            title="Popular"
                                            emoji={popularEmoji}
                                            onPick={pick}
                                        />
                                        <EmojiSection
                                            title="All"
                                            emoji={searchEmoji('')}
                                            onPick={pick}
                                            className="mt-2"
                                        />
                                    </>
                                ) : results.length === 0 ? (
                                    <p className="py-6 text-center text-sm text-muted-foreground">
                                        No emoji found.
                                    </p>
                                ) : (
                                    <EmojiGrid emoji={results} onPick={pick} />
                                )}
                            </div>
                        </>
                    );
                }}
            </PopoverPanel>
        </Popover>
    );
}

function EmojiSection({
    title,
    emoji,
    onPick,
    className,
}: {
    title: string;
    emoji: PickableEmoji[];
    onPick: (emoji: string) => void;
    className?: string;
}) {
    return (
        <section className={className}>
            <h3 className="px-1 pb-1 font-mono text-[11px] text-muted-foreground">
                {title}
            </h3>
            <EmojiGrid emoji={emoji} onPick={onPick} />
        </section>
    );
}

function EmojiGrid({
    emoji,
    onPick,
}: {
    emoji: PickableEmoji[];
    onPick: (emoji: string) => void;
}) {
    return (
        <div className="grid grid-cols-8 gap-0.5">
            {emoji.map(({ name, emoji: character }) => (
                <button
                    key={name}
                    type="button"
                    onClick={() => onPick(character)}
                    aria-label={name.replaceAll('_', ' ')}
                    title={`:${name}:`}
                    className="grid size-8 place-items-center rounded-md text-xl leading-none transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    data-test="emoji-picker-option"
                >
                    {character}
                </button>
            ))}
        </div>
    );
}
