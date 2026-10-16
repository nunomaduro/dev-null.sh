import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import DeviceController from '@/actions/App/Http/Controllers/DeviceController';
import { Button } from '@/components/ui/button';
import { useNow } from '@/hooks/use-now';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn, formatRelativeTime } from '@/lib/utils';
import type { Device } from '@/types';

export default function Index({ devices }: { devices: Device[] }) {
    const headingRef = useRef<HTMLHeadingElement>(null);

    // A revoked row unmounts with the focus inside it, so it moves to the next row, or the heading...
    const focusAfter = (id: string) => {
        const index = devices.findIndex((device) => device.id === id);
        const next = devices[index + 1] ?? devices[index - 1];

        const target = next
            ? document.querySelector<HTMLElement>(
                  `[data-device="${next.id}"] [data-revoke]`,
              )
            : null;

        (target ?? headingRef.current)?.focus({ preventScroll: true });
    };

    return (
        <>
            <Head title="Device settings" />

            <section className="px-4 py-6 sm:px-6">
                <header className="mb-5">
                    <h2
                        ref={headingRef}
                        tabIndex={-1}
                        className="text-lg font-semibold tracking-tight outline-none"
                    >
                        Approved devices
                    </h2>
                    <p className="mt-1 max-w-prose text-sm text-pretty text-muted-foreground">
                        Each one can use /dev/null as you through the app, since
                        you approved it here on the web. Revoke any you don't
                        use or don't recognize.
                    </p>
                </header>

                {devices.length === 0 ? (
                    <div className="rounded-lg border px-4 py-6 text-center">
                        <p className="font-mono text-[13px] text-muted-foreground">
                            fatal: no devices approved yet
                        </p>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-pretty text-muted-foreground">
                            Sign in from the /dev/null app and approve it here
                            to add one.
                        </p>
                    </div>
                ) : (
                    <ul className="overflow-hidden rounded-lg border">
                        {devices.map((device) => (
                            <DeviceRow
                                key={device.id}
                                device={device}
                                onRevoked={() => focusAfter(device.id)}
                            />
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

function DeviceRow({
    device,
    onRevoked,
}: {
    device: Device;
    onRevoked: () => void;
}) {
    const now = useNow();
    const [revocation, setRevocation] = useState<
        'idle' | 'confirming' | 'revoking'
    >('idle');
    const [hasCancelled, setHasCancelled] = useState(false);

    const cancel = () => {
        setRevocation('idle');
        setHasCancelled(true);
    };

    const revoke = () => {
        setRevocation('revoking');

        const prefersReducedMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        window.setTimeout(
            () =>
                router.visit(DeviceController.destroy(device), {
                    preserveScroll: true,
                    onSuccess: onRevoked,
                    onError: () => setRevocation('idle'),
                }),
            prefersReducedMotion ? 0 : 700,
        );
    };

    return (
        <li
            data-device={device.id}
            className={cn(
                'grid border-b transition-[grid-template-rows,opacity,background-color] duration-300 ease-out last:border-b-0',
                revocation === 'revoking'
                    ? 'grid-rows-[0fr] opacity-0 delay-400'
                    : 'grid-rows-[1fr]',
                { 'bg-destructive/8': revocation !== 'idle' },
            )}
        >
            <div className="min-h-0 overflow-hidden">
                <div className="flex items-center gap-3 px-4 py-3">
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-medium" title={device.name}>
                            {device.name}
                        </p>
                        <p
                            className="truncate font-mono text-[13px] leading-5 text-muted-foreground"
                            title={device.fingerprint}
                        >
                            {device.fingerprint}
                        </p>

                        {revocation === 'idle' && (
                            <p className="h-5 truncate text-sm leading-5 text-muted-foreground">
                                Approved{' '}
                                <Time date={device.approved_at} now={now} />
                                <span aria-hidden> · </span>
                                {device.last_used_at === null ? (
                                    'not used yet'
                                ) : (
                                    <>
                                        last used{' '}
                                        <Time
                                            date={device.last_used_at}
                                            now={now}
                                        />
                                    </>
                                )}
                            </p>
                        )}

                        {revocation === 'confirming' && (
                            <p className="h-5 truncate text-sm leading-5">
                                Revoke this device? The app on it signs out.
                            </p>
                        )}

                        {revocation === 'revoking' && (
                            <p
                                className="h-5 truncate font-mono text-[13px] leading-5 text-muted-foreground"
                                role="status"
                            >
                                rm {device.fingerprint} → /dev/null
                            </p>
                        )}
                    </div>

                    {revocation === 'idle' && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setRevocation('confirming')}
                            className="hover:bg-destructive/10 hover:text-destructive"
                            // Focus comes back here when the confirm is cancelled...
                            autoFocus={hasCancelled}
                            data-revoke
                        >
                            Revoke
                        </Button>
                    )}

                    {revocation === 'confirming' && (
                        <div
                            role="group"
                            aria-label="Confirm revoke"
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    cancel();
                                }
                            }}
                            className="flex shrink-0 gap-2"
                        >
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={revoke}
                            >
                                Revoke
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                autoFocus
                                onClick={cancel}
                            >
                                Cancel
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </li>
    );
}

/**
 * "just now", "3m ago", or a date, in mono like every time devnull prints.
 */
function Time({ date, now }: { date: string; now: Date }) {
    const relative = formatRelativeTime(date, now);

    return (
        <time
            dateTime={date}
            title={new Date(date).toLocaleString()}
            className="font-mono text-[13px] tabular-nums"
        >
            {relative === 'now'
                ? 'just now'
                : /^\d+[mhd]$/.test(relative)
                  ? `${relative} ago`
                  : relative}
        </time>
    );
}

Index.layout = [AppLayout, SettingsLayout];
