import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { GiphyResult } from '@/lib/giphy';
import {
    attachmentFromResult,
    fetchGifs,
    giphySearchUrl,
    giphyTrendingUrl,
    GiphyRateLimited,
    pageSize,
    previewUrlFor,
} from '@/lib/giphy';
import type { GiphyAttachment } from '@/lib/giphy';

type Status = 'loading' | 'ready' | 'error' | 'rate-limited';

export function GiphyPickerDialog({
    apiKey,
    open,
    onOpenChange,
    onPick,
}: {
    apiKey: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (gif: GiphyAttachment) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GiphyResult[]>([]);
    const [status, setStatus] = useState<Status>('loading');
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [reloads, setReloads] = useState(0);

    const urlFor = (offset: number) =>
        query.trim() === ''
            ? giphyTrendingUrl(apiKey, offset)
            : giphySearchUrl(apiKey, query.trim(), offset);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timeout = setTimeout(
            () => {
                setStatus('loading');
                fetchGifs(urlFor(0), controller.signal)
                    .then((gifs) => {
                        setResults(gifs);
                        setStatus('ready');
                    })
                    .catch((error: unknown) => {
                        if (controller.signal.aborted) {
                            return;
                        }

                        setStatus(
                            error instanceof GiphyRateLimited
                                ? 'rate-limited'
                                : 'error',
                        );
                    });
            },
            query === '' ? 0 : 300,
        );

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [open, query, apiKey, reloads]);

    const loadMore = () => {
        setIsLoadingMore(true);
        fetchGifs(urlFor(results.length))
            .then((gifs) => setResults((current) => [...current, ...gifs]))
            .catch(() => undefined)
            .finally(() => setIsLoadingMore(false));
    };

    const pick = (result: GiphyResult) => {
        const gif = attachmentFromResult(result);

        if (gif !== null) {
            onPick(gif);
            onOpenChange(false);
            setQuery('');
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[80vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Attach a GIF</DialogTitle>
                    <DialogDescription className="sr-only">
                        Search GIPHY and pick a GIF to attach.
                    </DialogDescription>
                </DialogHeader>
                <Input
                    autoFocus
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="grep giphy…"
                    aria-label="Search GIPHY"
                    data-test="giphy-search-input"
                />
                {status === 'loading' && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Loading…
                    </p>
                )}
                {status === 'rate-limited' && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        You’ve hit your GIPHY key’s search rate limit. Try again
                        shortly.
                    </p>
                )}
                {status === 'error' && (
                    <div className="py-8 text-center">
                        <p className="text-sm text-muted-foreground">
                            GIPHY did not answer.
                        </p>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="mt-2"
                            onClick={() => setReloads((count) => count + 1)}
                        >
                            Retry
                        </Button>
                    </div>
                )}
                {status === 'ready' && results.length === 0 && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        No GIFs matched. /dev/null remains empty.
                    </p>
                )}
                {status === 'ready' && results.length > 0 && (
                    <>
                        <ul
                            className="grid grid-cols-3 gap-1"
                            data-test="giphy-results"
                        >
                            {results.map((result) => {
                                const preview = previewUrlFor(result);

                                return preview === null ? null : (
                                    <li key={result.id}>
                                        <button
                                            type="button"
                                            onClick={() => pick(result)}
                                            className="block w-full overflow-hidden rounded-md border focus-visible:ring-2 focus-visible:ring-ring"
                                            aria-label={
                                                result.title === ''
                                                    ? 'Attach GIF'
                                                    : `Attach: ${result.title}`
                                            }
                                        >
                                            <img
                                                src={preview}
                                                alt={result.title}
                                                loading="lazy"
                                                referrerPolicy="no-referrer"
                                                className="h-24 w-full bg-muted object-cover"
                                            />
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                        {results.length % pageSize === 0 && (
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                disabled={isLoadingMore}
                                onClick={loadMore}
                                className="mx-auto"
                            >
                                {isLoadingMore ? 'Loading…' : 'Load more'}
                            </Button>
                        )}
                    </>
                )}
                <div className="flex justify-center border-t pt-3">
                    <a
                        href="https://giphy.com"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <span className="font-mono text-[11px] font-bold tracking-wide text-muted-foreground">
                            Powered By GIPHY
                        </span>
                    </a>
                </div>
            </DialogContent>
        </Dialog>
    );
}
