import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { KeyLabels } from '@/lib/keyboard';
import { useKeyLabels } from '@/lib/keyboard';

type Shortcut = { keys: string[]; label: string };

const shortcuts = (keys: KeyLabels): Shortcut[] => [
    { keys: ['j'], label: 'Next commit' },
    { keys: ['k'], label: 'Previous commit' },
    { keys: ['y'], label: 'Copy link to the focused commit' },
    { keys: ['↵'], label: 'Open the focused commit' },
    { keys: ['r'], label: 'Reply to commit' },
    { keys: ['q'], label: 'Quote commit' },
    { keys: ['o'], label: 'Show or hide replies' },
    { keys: ['c'], label: 'Write a commit' },
    { keys: [keys.mod, 'K'], label: 'Search developers and commits' },
    { keys: [keys.mod, 'B'], label: 'Collapse or expand navigation' },
    { keys: ['/'], label: 'Search developers and commits' },
    { keys: [keys.mod, '↵'], label: 'Commit (in the composer)' },
    { keys: [keys.mod, keys.shift, 'P'], label: 'Preview (in the composer)' },
    { keys: ['g', 'l'], label: 'Go to your log' },
    { keys: ['g', 'e'], label: 'Go to trending' },
    { keys: ['g', 'p'], label: 'Go to your profile' },
    { keys: ['g', 's'], label: 'Go to settings' },
    { keys: ['?'], label: 'Show this list' },
];

const moderationShortcuts: Shortcut[] = [
    { keys: ['a'], label: 'Approve commit (in the queue)' },
    { keys: ['x'], label: 'Reject commit (in the queue)' },
];

function ShortcutList({ shortcuts }: { shortcuts: Shortcut[] }) {
    return (
        <dl className="grid gap-x-8 text-sm sm:grid-cols-2">
            {shortcuts.map((shortcut) => (
                <div
                    key={shortcut.keys.join('')}
                    className="flex items-center justify-between gap-4 border-b py-2"
                >
                    <dt>{shortcut.label}</dt>
                    <dd className="flex shrink-0 gap-1">
                        {shortcut.keys.map((key) => (
                            <kbd
                                key={key}
                                className="min-w-6 rounded border border-b-2 bg-muted px-1.5 py-0.5 text-center font-mono text-xs"
                            >
                                {key}
                            </kbd>
                        ))}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function KeyboardShortcutsDialog({
    open,
    onOpenChange,
    canModerate,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canModerate: boolean;
}) {
    const keys = useKeyLabels();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogTitle>Keyboard shortcuts</DialogTitle>
                <DialogDescription>
                    They work anywhere except while you're typing.
                </DialogDescription>

                <ShortcutList shortcuts={shortcuts(keys)} />

                {canModerate && (
                    <section aria-labelledby="moderation-shortcuts">
                        <h3
                            id="moderation-shortcuts"
                            className="mb-1 text-sm font-semibold"
                        >
                            Moderation
                        </h3>
                        <ShortcutList shortcuts={moderationShortcuts} />
                    </section>
                )}
            </DialogContent>
        </Dialog>
    );
}
