import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/GiphyKeyController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { validateGiphyKey } from '@/lib/giphy';

export function GiphyKeySetting({
    giphyConnected,
}: {
    giphyConnected: boolean;
}) {
    const [key, setKey] = useState('');
    const [editing, setEditing] = useState(!giphyConnected);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const connect = async () => {
        if (processing) {
            return;
        }

        const candidate = key.trim();

        if (candidate === '') {
            return;
        }

        setProcessing(true);
        setError(null);

        const verdict = await validateGiphyKey(candidate);

        if (verdict === 'invalid') {
            setError('That key was rejected by GIPHY.');
            setProcessing(false);

            return;
        }

        if (verdict === 'unreachable') {
            setError("Couldn't reach GIPHY to verify the key.");
            setProcessing(false);

            return;
        }

        router.put(
            update.url(),
            { key: candidate },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    setError(errors.key ?? 'Could not save the key.'),
                onSuccess: () => {
                    setKey('');
                    setError(null);
                    setEditing(false);
                },
            },
        );
    };

    const remove = () => {
        if (processing) {
            return;
        }

        setError(null);

        router.delete(destroy.url(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: () => setError('Could not remove the key.'),
        });
    };

    return (
        <section className="px-4 py-6 sm:px-6">
            <h2 id="giphy-key-label" className="text-base font-semibold">
                Giphy
            </h2>
            <p
                id="giphy-key-description"
                className="mt-2 text-sm text-muted-foreground"
            >
                GIF search runs on your own GIPHY API key, under your own quota.
                Get a free key at{' '}
                <a
                    href="https://developers.giphy.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="underline underline-offset-4"
                >
                    developers.giphy.com
                </a>
                , connect it here, and every composer gains a GIF picker.
                Without a key, the picker stays hidden.
            </p>
            {giphyConnected && !editing ? (
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <p className="text-sm" data-test="giphy-connected">
                        GIPHY is connected.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={processing}
                        onClick={() => setEditing(true)}
                    >
                        Update
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                        onClick={remove}
                        data-test="giphy-remove-button"
                    >
                        {processing ? <Spinner /> : null}
                        Remove
                    </Button>
                </div>
            ) : (
                <div className="mt-4 max-w-md space-y-2">
                    <Label htmlFor="giphy-key-input">GIPHY API key</Label>
                    <div className="flex gap-2">
                        <Input
                            id="giphy-key-input"
                            value={key}
                            onChange={(event) => setKey(event.target.value)}
                            placeholder="Paste your GIPHY API key"
                            autoComplete="off"
                            data-test="giphy-key-input"
                        />
                        <Button
                            type="button"
                            onClick={() => connect()}
                            disabled={processing || key.trim() === ''}
                            data-test="giphy-connect-button"
                        >
                            {processing ? <Spinner /> : null}
                            {giphyConnected ? 'Update' : 'Connect'}
                        </Button>
                    </div>
                </div>
            )}
            {error !== null && (
                <p
                    className="mt-3 text-sm text-destructive"
                    role="alert"
                    data-test="giphy-key-error"
                >
                    {error}
                </p>
            )}
        </section>
    );
}
