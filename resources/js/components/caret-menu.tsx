import type { KeyboardEvent, ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import type { CaretMenuPosition } from '@/lib/caret-position';

/**
 * The shared keyboard rules of the caret menus: ↑↓ cycle, ↵ or Tab pick, Esc dismisses. Returns true when consumed.
 */
export function handleCaretMenuKeydown(
    event: KeyboardEvent<HTMLTextAreaElement>,
    {
        count,
        activeIndex,
        setActiveIndex,
        onSelect,
        onClose,
        canSelect,
    }: {
        count: number;
        activeIndex: number;
        setActiveIndex: (index: number) => void;
        onSelect: (index: number) => void;
        onClose: () => void;
        canSelect?: (index: number) => boolean;
    },
): boolean {
    if (event.nativeEvent.isComposing) {
        return false;
    }

    if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && count > 0) {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        setActiveIndex((activeIndex + step + count) % count);

        return true;
    }

    const hasModifier = event.metaKey || event.ctrlKey || event.altKey;

    if (
        (event.key === 'Enter' || event.key === 'Tab') &&
        !hasModifier &&
        !event.shiftKey &&
        count > 0 &&
        (canSelect === undefined || canSelect(activeIndex))
    ) {
        event.preventDefault();
        onSelect(activeIndex);

        return true;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        onClose();

        return true;
    }

    return false;
}

/**
 * The floating panel a caret menu renders into a portal, positioned under the caret.
 */
export function CaretMenuPortal({
    marker,
    position,
    testId,
    children,
}: {
    marker: 'mention' | 'slash' | 'emoji';
    position: CaretMenuPosition;
    testId: string;
    children: ReactNode;
}) {
    return createPortal(
        <div
            className="pointer-events-auto fixed z-50 w-72 overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-[0_12px_32px_-12px_rgb(0_0_0/0.35)] motion-safe:animate-in motion-safe:fade-in-0 motion-safe:zoom-in-95"
            style={position}
            onMouseDown={(event) => event.preventDefault()}
            {...(marker === 'mention'
                ? { 'data-mention-suggestions': true }
                : marker === 'slash'
                  ? { 'data-slash-suggestions': true }
                  : { 'data-emoji-suggestions': true })}
            data-test={testId}
        >
            {children}
        </div>,
        document.body,
    );
}

/**
 * The option list of a caret menu, keeping the active option in view.
 */
export function CaretMenuList({
    id,
    label,
    activeIndex,
    children,
}: {
    id: string;
    label: string;
    activeIndex: number;
    children: ReactNode;
}) {
    const listRef = useRef<HTMLUListElement>(null);

    useEffect(() => {
        listRef.current
            ?.querySelector('[aria-selected="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex]);

    return (
        <ul
            ref={listRef}
            id={id}
            role="listbox"
            aria-label={label}
            className="max-h-80 overflow-y-auto p-1.5"
        >
            {children}
        </ul>
    );
}
