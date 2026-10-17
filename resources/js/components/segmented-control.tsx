import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

export function SegmentedControl<T>({
    label,
    name,
    options,
    value,
    onChange,
    className,
}: {
    label: string;
    name: string;
    options: { value: T; icon: LucideIcon; label: string }[];
    value: T;
    onChange: (value: T) => void;
    className?: string;
}) {
    const selectedIndex = options.findIndex((option) => option.value === value);

    const handleKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        index: number,
    ): void => {
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        let nextIndex: number;

        switch (event.key) {
            case 'ArrowRight':
            case 'ArrowDown':
                nextIndex = (index + 1) % options.length;
                break;
            case 'ArrowLeft':
            case 'ArrowUp':
                nextIndex = (index - 1 + options.length) % options.length;
                break;
            case 'Home':
                nextIndex = 0;
                break;
            case 'End':
                nextIndex = options.length - 1;
                break;
            default:
                return;
        }

        event.preventDefault();
        event.currentTarget.parentElement
            ?.querySelectorAll<HTMLButtonElement>('button[role="radio"]')
            [nextIndex]?.focus();

        if (nextIndex !== index || options[nextIndex].value !== value) {
            onChange(options[nextIndex].value);
        }
    };

    return (
        <div
            role="radiogroup"
            aria-label={label}
            className={cn('inline-flex gap-1 rounded-lg border p-1', className)}
        >
            {options.map((option, index) => (
                <button
                    key={option.label}
                    type="button"
                    role="radio"
                    aria-checked={option.value === value}
                    tabIndex={index === Math.max(selectedIndex, 0) ? 0 : -1}
                    onClick={() => onChange(option.value)}
                    onKeyDown={(event) => handleKeyDown(event, index)}
                    className={cn(
                        'relative isolate rounded-md px-3.5 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                        {
                            'text-primary hover:bg-transparent hover:text-primary':
                                option.value === value,
                        },
                    )}
                >
                    {option.value === value && (
                        <span
                            aria-hidden
                            className="absolute inset-0 -z-10 rounded-md bg-primary-soft in-place:[view-transition-name:var(--transition-name)]"
                            style={
                                {
                                    '--transition-name': `${name}-active`,
                                } as CSSProperties
                            }
                        />
                    )}
                    <span
                        className="flex items-center gap-2 [view-transition-class:indicator-label] in-place:[view-transition-name:var(--transition-name)]"
                        style={
                            {
                                '--transition-name': `${name}-${option.label.toLowerCase()}`,
                            } as CSSProperties
                        }
                    >
                        <option.icon className="size-4" />
                        {option.label}
                    </span>
                </button>
            ))}
        </div>
    );
}
