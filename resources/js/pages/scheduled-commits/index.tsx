import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowLeft, Clock3, ImageIcon, LockKeyhole } from 'lucide-react';
import { useState } from 'react';
import LogController from '@/actions/App/Http/Controllers/LogController';
import ScheduledCommitController from '@/actions/App/Http/Controllers/ScheduledCommitController';
import { FoldedCommitBody } from '@/components/commit-body';
import { Button } from '@/components/ui/button';
import { useNow } from '@/hooks/use-now';
import AppLayout from '@/layouts/app-layout';
import { emojify } from '@/lib/emoji';
import type { BodySegment } from '@/types';

type ScheduledCommit = {
    id: string;
    body: string | null;
    body_segments: BodySegment[];
    preview_segments: BodySegment[] | null;
    poll_options: string[] | null;
    image_count: number;
    scheduled_at: string;
    submitted_at: string | null;
    status: 'scheduled' | 'pending' | 'approved' | 'rejected' | 'deleted';
};

export default function Index({
    scheduledCommits,
}: {
    scheduledCommits: {
        data: ScheduledCommit[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}) {
    const now = useNow();
    const [canceling, setCanceling] = useState<string | null>(null);
    usePoll(30_000, { only: ['scheduledCommits'] });

    const labels = {
        pending: 'Submitted for review',
        approved: 'Approved',
        rejected: 'Rejected',
        deleted: 'Deleted',
    };

    return (
        <>
            <Head title="Scheduled commits" />
            <div className="flex h-14 items-center gap-3 border-b px-4">
                <Link
                    href={LogController.show('following')}
                    aria-label="Back to Log"
                    className="rounded-md p-2 text-muted-foreground hover:bg-accent"
                >
                    <ArrowLeft className="size-4" />
                </Link>
                <h1 className="font-semibold">Scheduled commits</h1>
                <LockKeyhole
                    className="ml-auto size-4 text-muted-foreground"
                    aria-label="Private"
                />
            </div>
            <div className="border-b px-5 py-4 sm:px-6">
                <p className="text-sm leading-relaxed text-muted-foreground">
                    Only you can see this list. At the scheduled time, each
                    commit goes through the usual review process. Scheduling is
                    not approval.
                </p>
                <p className="mt-2 font-mono text-xs text-muted-foreground">
                    Times in {Intl.DateTimeFormat().resolvedOptions().timeZone}
                </p>
            </div>
            {scheduledCommits.data.length === 0 ? (
                <div className="space-y-3 px-6 py-14 text-center">
                    <Clock3 className="mx-auto size-6 text-muted-foreground" />
                    <p className="font-medium">No scheduled commits</p>
                    <p className="text-sm text-muted-foreground">
                        Choose Schedule in the composer to submit later.
                    </p>
                    <Button asChild variant="outline" size="sm">
                        <Link href={LogController.show('following')}>
                            Back to Log
                        </Link>
                    </Button>
                </div>
            ) : (
                <ul className="divide-y">
                    {scheduledCommits.data.map((scheduled) => {
                        const due =
                            new Date(scheduled.scheduled_at).getTime() <=
                            now.getTime();
                        return (
                            <li
                                key={scheduled.id}
                                className="space-y-3 px-5 py-5 sm:px-6"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium">
                                            {scheduled.status === 'scheduled'
                                                ? due
                                                    ? 'Waiting for submission'
                                                    : 'Scheduled'
                                                : labels[scheduled.status]}
                                        </p>
                                        <time
                                            dateTime={scheduled.scheduled_at}
                                            className="mt-1 block font-mono text-xs text-muted-foreground"
                                        >
                                            {new Date(
                                                scheduled.scheduled_at,
                                            ).toLocaleString(undefined, {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            })}
                                        </time>
                                    </div>
                                    {scheduled.status === 'scheduled' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={canceling !== null}
                                            aria-label={`Cancel the commit scheduled for ${new Date(scheduled.scheduled_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`}
                                            onClick={() => {
                                                setCanceling(scheduled.id);
                                                router.delete(
                                                    ScheduledCommitController.destroy(
                                                        scheduled.id,
                                                    ),
                                                    {
                                                        preserveScroll: true,
                                                        onFinish: () =>
                                                            setCanceling(null),
                                                    },
                                                );
                                            }}
                                        >
                                            {canceling === scheduled.id
                                                ? 'Canceling…'
                                                : 'Cancel'}
                                        </Button>
                                    )}
                                </div>
                                {scheduled.body && (
                                    <FoldedCommitBody
                                        segments={scheduled.body_segments}
                                        mentions={null}
                                        previewSegments={
                                            scheduled.preview_segments
                                        }
                                        className="text-[15px]"
                                    />
                                )}
                                {scheduled.poll_options && (
                                    <ul
                                        aria-label="Poll options"
                                        className="space-y-2"
                                    >
                                        {scheduled.poll_options.map(
                                            (option) => (
                                                <li
                                                    key={option}
                                                    className="rounded-md border px-3 py-2 text-[15px] [overflow-wrap:anywhere]"
                                                >
                                                    {emojify(option)}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                                {scheduled.image_count > 0 && (
                                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                        <ImageIcon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {scheduled.image_count === 1
                                            ? '1 image'
                                            : `${scheduled.image_count} images`}
                                    </p>
                                )}
                                {scheduled.status === 'scheduled' && (
                                    <p className="text-xs text-muted-foreground">
                                        {due
                                            ? 'Waiting for the scheduler. If submission fails, it is retried automatically.'
                                            : 'Private until submitted. You can cancel before submission.'}
                                    </p>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            {(scheduledCommits.prev_page_url ||
                scheduledCommits.next_page_url) && (
                <div className="flex justify-between border-t px-6 py-4 text-sm text-primary">
                    {scheduledCommits.prev_page_url ? (
                        <Link href={scheduledCommits.prev_page_url}>
                            Previous
                        </Link>
                    ) : (
                        <span />
                    )}
                    {scheduledCommits.next_page_url && (
                        <Link href={scheduledCommits.next_page_url}>Next</Link>
                    )}
                </div>
            )}
        </>
    );
}

Index.layout = AppLayout;
