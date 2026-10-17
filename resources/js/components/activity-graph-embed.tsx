import type { LucideIcon } from 'lucide-react';
import { Check, Copy, Monitor, Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { SegmentedControl } from '@/components/segmented-control';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useUser } from '@/hooks/use-user';

type GraphTheme = 'light' | 'dark' | 'auto';

const themes: { value: GraphTheme; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'auto', icon: Monitor, label: 'Auto' },
];

function themedUrl(url: string, theme: GraphTheme): string {
    return theme === 'light' ? url : `${url}?theme=${theme}`;
}

export default function ActivityGraphEmbed({
    activityGraphUrl,
    profileUrl,
}: {
    activityGraphUrl: string;
    profileUrl: string;
}) {
    const { user } = useUser();
    const [, copy] = useClipboard();
    const [theme, setTheme] = useState<GraphTheme>('light');
    const [isCopied, setIsCopied] = useState(false);

    const imageUrl = themedUrl(activityGraphUrl, theme);
    const snippet = `[![/dev/null activity](${imageUrl})](${profileUrl})`;
    const previewUrl = `${imageUrl}${theme === 'light' ? '?' : '&'}lane=${user.lane}`;

    useEffect(() => {
        if (!isCopied) {
            return;
        }

        const timeout = setTimeout(() => setIsCopied(false), 2000);

        return () => clearTimeout(timeout);
    }, [isCopied]);

    const copySnippet = async () => {
        if (await copy(snippet)) {
            setIsCopied(true);
            toast(
                <span className="font-mono text-[13px]">
                    Copied the Markdown. Paste it into your README.
                </span>,
            );

            return;
        }

        toast.error(
            <span className="font-mono text-[13px]">
                fatal: could not copy the Markdown
            </span>,
        );
    };

    const CopyIcon = isCopied ? Check : Copy;

    return (
        <section className="px-4 py-6 sm:px-6" data-test="activity-graph-embed">
            <Heading
                title="Embed your activity graph"
                description="Your commit graph as an SVG for your GitHub profile README, or anywhere Markdown renders. It only shows approved commits and refreshes about once an hour."
            />

            <SegmentedControl
                label="Graph theme"
                name="graph-theme"
                options={themes}
                value={theme}
                onChange={setTheme}
            />
            <p className="mt-2 text-sm text-muted-foreground">
                {theme === 'auto'
                    ? 'Follows the color scheme of whoever is reading.'
                    : `Always ${theme}, whatever the reader prefers.`}
            </p>

            <div className="mt-5 overflow-hidden rounded-lg border bg-background p-3 sm:p-4">
                <img
                    src={previewUrl}
                    alt={`Commit activity graph of @${user.username}`}
                    width={754}
                    height={196}
                    className="h-auto w-full"
                    data-test="activity-graph-preview"
                />
            </div>

            <div className="mt-5 rounded-lg border">
                <div className="flex items-center justify-between gap-3 border-b py-1.5 pr-1.5 pl-4">
                    <span className="font-mono text-[13px] text-muted-foreground">
                        README.md
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={copySnippet}
                        data-test="copy-embed-button"
                    >
                        <CopyIcon />
                        {isCopied ? 'Copied' : 'Copy Markdown'}
                    </Button>
                </div>
                <pre className="overflow-x-auto px-4 py-3 font-mono text-[13px] break-all whitespace-pre-wrap">
                    <code data-test="embed-snippet">{snippet}</code>
                </pre>
            </div>
        </section>
    );
}
