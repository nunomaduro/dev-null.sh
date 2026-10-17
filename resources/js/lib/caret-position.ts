export type CaretMenuPosition = {
    left: number;
    top?: number;
    bottom?: number;
};

// Every style that changes where text wraps, so the mirror below wraps exactly like the textarea.
const mirroredProperties = [
    'box-sizing',
    'width',
    'border-top-width',
    'border-right-width',
    'border-bottom-width',
    'border-left-width',
    'padding-top',
    'padding-right',
    'padding-bottom',
    'padding-left',
    'font-family',
    'font-size',
    'font-weight',
    'font-style',
    'font-stretch',
    'font-feature-settings',
    'font-variant',
    'line-height',
    'letter-spacing',
    'word-spacing',
    'text-transform',
    'text-indent',
    'tab-size',
];

/**
 * Where a character of the textarea is drawn, measured on a hidden copy of it that wraps the same way.
 */
function characterRect(textarea: HTMLTextAreaElement, index: number) {
    const style = window.getComputedStyle(textarea);
    const mirror = document.createElement('div');
    const marker = document.createElement('span');

    mirroredProperties.forEach((property) =>
        mirror.style.setProperty(property, style.getPropertyValue(property)),
    );
    mirror.style.position = 'absolute';
    mirror.style.visibility = 'hidden';
    mirror.style.top = '0';
    mirror.style.left = '-9999px';
    mirror.style.whiteSpace = 'pre-wrap';
    mirror.style.overflowWrap = 'break-word';

    mirror.textContent = textarea.value.slice(0, index);
    marker.textContent = textarea.value.slice(index) || '.';
    mirror.appendChild(marker);
    document.body.appendChild(mirror);

    const rect = textarea.getBoundingClientRect();
    const lineHeight =
        Number.parseFloat(style.lineHeight) ||
        Number.parseFloat(style.fontSize) * 1.4;
    const top = rect.top + marker.offsetTop - textarea.scrollTop;
    const left = rect.left + marker.offsetLeft - textarea.scrollLeft;

    mirror.remove();

    return { top, left, bottom: top + lineHeight };
}

/**
 * Where a caret menu anchored at a character of the textarea should sit, flipped above when there is no room below.
 */
export function caretMenuPosition(
    textarea: HTMLTextAreaElement,
    index: number,
    menu: { width: number; height: number },
): CaretMenuPosition {
    const character = characterRect(textarea, index);
    const left = Math.min(
        Math.max(character.left - 12, 8),
        window.innerWidth - menu.width - 8,
    );

    return character.bottom + 6 + menu.height > window.innerHeight &&
        character.top > menu.height
        ? { left, bottom: window.innerHeight - character.top + 6 }
        : { left, top: character.bottom + 6 };
}
