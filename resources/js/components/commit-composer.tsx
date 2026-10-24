import { Form, Link, usePage } from '@inertiajs/react';
import { ChartBar, Clock3 } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import ScheduledCommitController from '@/actions/App/Http/Controllers/ScheduledCommitController';
import { ActionTooltip } from '@/components/action-tooltip';
import { BodyCounter } from '@/components/body-counter';
import {
    ImageDropOverlay,
    ImagePickerButton,
    ImagePickerPreviews,
    imageErrorFrom,
} from '@/components/commit-image-picker';
import { CommitRulesDialog, useCommitRules } from '@/components/commit-rules';
import {
    ComposerPollPreview,
    ComposerPreviewBody,
    PreviewToggle,
    useComposerPreview,
} from '@/components/composer-preview';
import { GraphRail, RailAvatar } from '@/components/graph-rail';
import InputError from '@/components/input-error';
import type { UseIssueCommandReturn } from '@/components/issue-command';
import {
    IssueCommandBadge,
    IssueCommandHint,
    IssueCommandSuggestions,
    useIssueCommand,
} from '@/components/issue-command';
import { LinkCardPicker } from '@/components/link-card-picker';
import { MentionTextarea } from '@/components/mention-textarea';
import type { PollDraft } from '@/components/poll-editor';
import { emptyPollDraft, PollEditor } from '@/components/poll-editor';
import {
    isExistingLocalTime,
    SchedulePicker,
} from '@/components/schedule-picker';
import { StashLabel } from '@/components/stash-label';
import { Button } from '@/components/ui/button';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { useLinkCard } from '@/hooks/use-link-card';
import type { UseLinkCardReturn } from '@/hooks/use-link-card';
import type { UseStashReturn } from '@/hooks/use-stash';
import {
    discardSeriesStash,
    moveCaretToEnd,
    useStash,
} from '@/hooks/use-stash';
import { useUser } from '@/hooks/use-user';
import {
    bodyCharacterCount,
    isSavedAs,
    maxBodyLength,
    maxRawBodyLength,
    minBodyLength,
} from '@/lib/commit';
import { hasModKey, useKeyLabels } from '@/lib/keyboard';
import {
    characterCount,
    cn,
    isRejectedSubmission,
    laneColor,
} from '@/lib/utils';
import { show } from '@/routes/user';
import type { User } from '@/types';

// The ring moves quickly while a post starts and slows toward the limit, yet only closes at the limit.
function bodyProgress(count: number): number {
    return Math.log1p(count) / Math.log1p(maxBodyLength);
}

// The composer remounts whenever the dialog closes, so each shared text is only added to the draft once.
const addedIntents = new WeakSet<object>();

function focusBody(id: string): void {
    requestAnimationFrame(() => document.getElementById(id)?.focus());
}

function useCommitSchedule() {
    const [isScheduling, setIsScheduling] = useState(false);
    const [scheduledAt, setScheduledAt] = useState('');
    const [timezone, setTimezone] = useState('');

    return {
        isScheduling,
        setIsScheduling,
        scheduledAt,
        setScheduledAt,
        timezone,
        setTimezone,
    };
}

function CommitComposerBody({
    id,
    isInDialog,
    user,
    stash,
    attachments,
    linkCard,
    schedule,
    poll,
    setPoll,
    commitRules,
    isSubmittable,
    processing,
    issue,
    errors,
    onKeyDown,
    drop,
}: {
    id: string;
    isInDialog: boolean;
    user: User;
    stash: UseStashReturn;
    attachments: ReturnType<typeof useImageAttachments>;
    linkCard: UseLinkCardReturn;
    schedule: ReturnType<typeof useCommitSchedule>;
    poll: PollDraft | null;
    setPoll: (poll: PollDraft | null) => void;
    commitRules: ReturnType<typeof useCommitRules>;
    isSubmittable: boolean;
    processing: boolean;
    issue: UseIssueCommandReturn;
    errors: Record<string, string>;
    onKeyDown: (event: KeyboardEvent<HTMLTextAreaElement>) => void;
    drop: () => void;
}) {
    const keys = useKeyLabels();
    const { body, isRestored } = stash;
    const { auth } = usePage().props;
    const {
        isScheduling,
        scheduledAt,
        setScheduledAt,
        timezone,
        setTimezone,
        setIsScheduling,
    } = schedule;
    const hasImages = attachments.images.length > 0;
    const progress = bodyProgress(
        issue.command !== null
            ? characterCount(body)
            : bodyCharacterCount(body),
    );
    const isFilled = hasImages || characterCount(body.trim()) >= minBodyLength;
    const error = errors.body ?? errors.link_preview_url;
    const scheduleError =
        errors.scheduled_at ??
        (scheduledAt && !isExistingLocalTime(scheduledAt)
            ? 'This time does not exist in your timezone. Choose another time.'
            : undefined);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const textBox = useRef<HTMLDivElement>(null);
    const preview = useComposerPreview({ body, textareaRef, isSubmittable });
    const isIssue = issue.command !== null;

    // When the dialog's text box shrinks, as the keyboard opens or an image is added, it keeps the caret in view.
    useEffect(() => {
        const box = textBox.current;
        const textarea = textareaRef.current;

        if (!isInDialog || box === null || textarea === null) {
            return;
        }

        const observer = new ResizeObserver(() => {
            if (
                document.activeElement === textarea &&
                textarea.selectionEnd === textarea.value.length
            ) {
                box.scrollTop = box.scrollHeight;
            }
        });

        observer.observe(box);

        return () => observer.disconnect();
    }, [isInDialog]);

    return (
        <div
            className={cn('relative flex', { 'min-h-0': isInDialog })}
            {...(poll === null ? attachments.dropzone : {})}
        >
            {attachments.isDraggingFiles && (
                <ImageDropOverlay className="inset-2" />
            )}
            <GraphRail
                lane={laneColor(user.lane)}
                variant="staged"
                isFilled={isFilled}
                hasTail={!isInDialog}
                node={
                    isInDialog ? (
                        <RailAvatar user={user} progress={progress} />
                    ) : (
                        <Link
                            href={show(user)}
                            aria-label="View your profile"
                            className="flex rounded-full"
                        >
                            <RailAvatar user={user} progress={progress} />
                        </Link>
                    )
                }
            />

            <div
                className={cn('flex min-w-0 flex-1 py-3 pr-4', {
                    'border-b': !isInDialog,
                })}
            >
                <div
                    className={cn('min-w-0 flex-1', {
                        'flex min-h-0 flex-col': isInDialog,
                    })}
                >
                    <label htmlFor={id} className="sr-only">
                        Body
                    </label>
                    {issue.command && (
                        <IssueCommandBadge
                            command={issue.command}
                            onExit={() => {
                                issue.exit();
                                focusBody(id);
                            }}
                        />
                    )}
                    {/* In the dialog only the text scrolls, so the images and actions below it always keep their room. */}
                    <div
                        ref={textBox}
                        className={cn('grid', {
                            'min-h-12 shrink overflow-y-auto overscroll-contain':
                                isInDialog,
                        })}
                    >
                        {issue.command && (
                            <div
                                aria-hidden="true"
                                className="invisible col-start-1 row-start-1 max-h-[60dvh] overflow-hidden py-1.5 text-[17px] leading-relaxed whitespace-pre-wrap"
                            >
                                {issue.command.placeholder}
                            </div>
                        )}
                        <MentionTextarea
                            ref={textareaRef}
                            id={id}
                            name="body"
                            rows={1}
                            value={body}
                            onValueChange={issue.change}
                            onPaste={
                                poll === null ? attachments.onPaste : undefined
                            }
                            onKeyDown={(event) =>
                                preview.handleShortcut(event) ||
                                onKeyDown(event)
                            }
                            onFocus={(event) => {
                                issue.focus();

                                if (isRestored) {
                                    moveCaretToEnd(event);
                                }
                            }}
                            onBlur={issue.blur}
                            maxLength={
                                isIssue ? maxBodyLength : maxRawBodyLength
                            }
                            placeholder={
                                issue.command?.placeholder ??
                                (poll ? 'Ask a question' : 'What did you ship?')
                            }
                            autoComplete="off"
                            aria-invalid={error ? true : undefined}
                            aria-describedby={`${id}-count`}
                            className={cn(
                                'col-start-1 row-start-1 block field-sizing-content max-h-[60dvh] min-h-10 w-full resize-none overflow-x-hidden overflow-y-auto bg-transparent py-1.5 text-[17px] leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:outline-none',
                                {
                                    invisible: preview.isPreviewing,
                                    'max-h-none': isInDialog,
                                },
                            )}
                        />
                        {preview.isPreviewing && (
                            <ComposerPreviewBody
                                preview={preview}
                                url={linkCard.link}
                                images={attachments.images}
                                descriptions={attachments.descriptions}
                                className="col-start-1 row-start-1 py-1.5"
                            />
                        )}
                    </div>

                    {issue.hint && (
                        <IssueCommandHint
                            command={issue.hint}
                            onUse={(kind) => {
                                issue.start(kind, body);
                                focusBody(id);
                            }}
                            onDismiss={issue.dismissHint}
                        />
                    )}

                    {issue.suggestions.length > 0 && (
                        <IssueCommandSuggestions
                            suggestions={issue.suggestions}
                            selected={issue.selected}
                            onPick={(kind) => {
                                issue.start(kind, '');
                                focusBody(id);
                            }}
                        />
                    )}

                    <CommitRulesDialog
                        rules={commitRules}
                        textarea={textareaRef}
                    />
                    <InputError message={error} />

                    {!preview.isPreviewing && (
                        <ImagePickerPreviews
                            images={attachments.images}
                            descriptions={attachments.descriptions}
                            onDescribe={attachments.describe}
                            onRemove={attachments.remove}
                        />
                    )}
                    <InputError
                        message={attachments.error ?? imageErrorFrom(errors)}
                        className="mt-1"
                    />

                    {poll && (
                        <div className="mt-2 grid">
                            <PollEditor
                                id={`${id}-poll`}
                                poll={poll}
                                errors={errors}
                                onChange={setPoll}
                                onRemove={() => {
                                    setPoll(null);
                                    focusBody(id);
                                }}
                                className={cn('col-start-1 row-start-1', {
                                    invisible: preview.isPreviewing,
                                })}
                            />
                            {preview.isPreviewing && (
                                <ComposerPollPreview
                                    poll={poll}
                                    className="col-start-1 row-start-1"
                                />
                            )}
                        </div>
                    )}

                    {isScheduling && (
                        <div
                            id={`${id}-schedule`}
                            className="mt-3 space-y-2 rounded-lg border bg-muted/30 p-3"
                        >
                            <label
                                htmlFor={`${id}-scheduled-at`}
                                className="text-sm font-medium"
                            >
                                Submit at
                            </label>
                            <SchedulePicker
                                id={`${id}-scheduled-at`}
                                value={scheduledAt}
                                onChange={setScheduledAt}
                                invalid={scheduleError ? true : undefined}
                                describedBy={`${id}-schedule-help ${id}-schedule-error`}
                            />
                            <p
                                id={`${id}-schedule-help`}
                                className="text-xs leading-relaxed text-muted-foreground"
                            >
                                {timezone}
                                {scheduledAt &&
                                !Number.isNaN(new Date(scheduledAt).getTime())
                                    ? ` (${new Intl.DateTimeFormat(undefined, { timeZoneName: 'shortOffset' }).formatToParts(new Date(scheduledAt)).find((part) => part.type === 'timeZoneName')?.value})`
                                    : ''}
                                . Within 30 days. Private until submitted;{' '}
                                {auth.isAdmin
                                    ? 'your commit will be approved automatically.'
                                    : 'then submitted for review, not published automatically.'}
                            </p>
                            <InputError
                                id={`${id}-schedule-error`}
                                message={scheduleError}
                            />
                            <Link
                                href={ScheduledCommitController.index()}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                Scheduled commits →
                            </Link>
                        </div>
                    )}

                    <div className="mt-2 flex flex-wrap items-center justify-end gap-x-4 gap-y-2 sm:flex-nowrap">
                        <div className="flex min-w-0 flex-1 items-center gap-x-1 max-sm:basis-full">
                            {!isIssue && (
                                <>
                                    <ImagePickerButton
                                        count={attachments.images.length}
                                        disabled={processing || poll !== null}
                                        reading={attachments.reading}
                                        onPick={attachments.pick}
                                        className="-ml-2"
                                    />
                                    <ActionTooltip
                                        label={
                                            hasImages
                                                ? 'A commit can have images or a poll, not both'
                                                : 'Add poll'
                                        }
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            disabled={
                                                processing ||
                                                attachments.reading ||
                                                poll !== null ||
                                                hasImages
                                            }
                                            onClick={() => {
                                                setPoll(emptyPollDraft);
                                                requestAnimationFrame(() =>
                                                    document
                                                        .getElementById(
                                                            `${id}-poll-option-0`,
                                                        )
                                                        ?.focus(),
                                                );
                                            }}
                                            aria-label="Add poll"
                                            className="text-muted-foreground"
                                            data-test="add-poll-button"
                                        >
                                            <ChartBar className="size-5" />
                                        </Button>
                                    </ActionTooltip>
                                    <ActionTooltip
                                        label={
                                            isScheduling
                                                ? "Don't schedule"
                                                : 'Schedule for later'
                                        }
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Schedule"
                                            aria-expanded={isScheduling}
                                            aria-controls={`${id}-schedule`}
                                            disabled={processing}
                                            onClick={() => {
                                                setTimezone(
                                                    Intl.DateTimeFormat().resolvedOptions()
                                                        .timeZone,
                                                );
                                                setScheduledAt('');
                                                setIsScheduling(!isScheduling);
                                            }}
                                            className="text-muted-foreground aria-expanded:bg-primary-soft aria-expanded:text-primary aria-expanded:hover:bg-primary-soft aria-expanded:hover:text-primary"
                                            data-test="schedule-toggle"
                                        >
                                            <Clock3 className="size-5" />
                                        </Button>
                                    </ActionTooltip>
                                    <LinkCardPicker
                                        linkCard={linkCard}
                                        disabled={processing}
                                        labelClassName="max-sm:sr-only"
                                    />
                                    <PreviewToggle preview={preview} />
                                </>
                            )}
                            {isRestored && (
                                <StashLabel
                                    onDrop={() => {
                                        drop();
                                        focusBody(id);
                                    }}
                                    className="ml-2 text-[13px]"
                                />
                            )}
                        </div>
                        <BodyCounter
                            id={`${id}-count`}
                            body={body}
                            isIssue={isIssue}
                            className="text-[13px]"
                        />
                        {isIssue ? (
                            <ActionTooltip
                                label="Open issue"
                                shortcut={keys.combo(keys.mod, keys.enter)}
                            >
                                <Button
                                    type="button"
                                    disabled={
                                        issue.processing || !isSubmittable
                                    }
                                    onClick={() => {
                                        if (attachments.isReading()) {
                                            return;
                                        }

                                        void issue.submit();
                                    }}
                                    className="min-w-30"
                                    data-test="open-issue-button"
                                >
                                    {issue.processing
                                        ? 'Opening…'
                                        : 'Open issue'}
                                </Button>
                            </ActionTooltip>
                        ) : (
                            <ActionTooltip
                                label={
                                    isScheduling ? 'Schedule commit' : 'Commit'
                                }
                                shortcut={keys.combo(keys.mod, keys.enter)}
                            >
                                <Button
                                    type="submit"
                                    disabled={processing || !isSubmittable}
                                    className="min-w-30"
                                    data-test="create-commit-button"
                                >
                                    {isScheduling
                                        ? processing
                                            ? 'Scheduling…'
                                            : 'Schedule commit'
                                        : processing
                                          ? 'Committing…'
                                          : 'Commit'}
                                </Button>
                            </ActionTooltip>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

export function CommitComposer({
    id = 'body',
    isInDialog = false,
    onCommitted,
}: {
    id?: string;
    isInDialog?: boolean;
    onCommitted?: () => void;
}) {
    const schedule = useCommitSchedule();
    const { isScheduling, scheduledAt, setScheduledAt, setIsScheduling } =
        schedule;
    const { user } = useUser();
    const stash = useStash(user.id, 'commit');
    const { body } = stash;
    const linkCard = useLinkCard(body);
    const [draftVersion, setDraftVersion] = useState(0);
    const drop = () => {
        stash.drop();
        linkCard.reset();
        setDraftVersion((version) => version + 1);
    };
    const attachments = useImageAttachments(
        stash.images,
        stash.descriptions,
        stash.updateImages,
        stash.key,
    );
    const [poll, setPoll] = useState<PollDraft | null>(null);
    const commitRules = useCommitRules();
    const isPosting = useRef(false);

    const hasImages = attachments.images.length > 0;
    const issue = useIssueCommand({
        body,
        updateBody: stash.updateBody,
        enabled:
            !attachments.reading &&
            !hasImages &&
            poll === null &&
            !isScheduling,
        onCreated: () => {
            drop();
            focusBody(id);
        },
    });

    const isCommittable =
        !attachments.reading &&
        (hasImages || characterCount(body.trim()) >= minBodyLength) &&
        (issue.command !== null
            ? characterCount(body)
            : bodyCharacterCount(body)) <= maxBodyLength &&
        (poll === null ||
            poll.options.every((option) => option.trim().length > 0));

    const isSubmittable =
        isCommittable &&
        (!isScheduling ||
            (scheduledAt !== '' && isExistingLocalTime(scheduledAt)));

    useEffect(() => discardSeriesStash(user.id), [user.id]);

    const { flash } = usePage();
    const { committed, intent } = flash;

    // A deploy reloads the page before onSuccess can drop the draft, so the reloaded page names the commit it became.
    useEffect(() => {
        if (committed !== undefined && isSavedAs(body, committed.body)) {
            drop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // A link shared to /intent/commit lands here, added after whatever the draft already holds rather than over it.
    useEffect(() => {
        if (isInDialog || intent === undefined || addedIntents.has(flash)) {
            return;
        }

        addedIntents.add(flash);
        stash.updateBody(
            body.trim() === '' ? intent : `${body}\n\n${intent}`,
        );
        focusBody(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            issue.handleKeyDown(
                event,
                isCommittable && !attachments.isReading(),
            )
        ) {
            return;
        }

        if (
            event.key === 'Enter' &&
            hasModKey(event) &&
            isSubmittable &&
            !attachments.isReading()
        ) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <Form
            className={cn({ 'flex min-h-0 flex-col': isInDialog })}
            {...(isScheduling
                ? ScheduledCommitController.store.form()
                : CommitController.store.form())}
            onBefore={() =>
                !isPosting.current &&
                !attachments.isReading() &&
                issue.command === null &&
                commitRules.beforeSubmit()
            }
            onStart={() => {
                isPosting.current = true;
            }}
            onFinish={() => {
                isPosting.current = false;
            }}
            transform={(data) => ({
                ...data,
                commit_rules_accepted: commitRules.hasAccepted(),
                ...(hasImages && {
                    images: attachments.images,
                    image_descriptions: attachments.descriptions,
                }),
                ...(isScheduling && {
                    scheduled_at: new Date(scheduledAt)
                        .toISOString()
                        .replace(/\.\d{3}Z$/, '+00:00'),
                }),
            })}
            options={{ preserveScroll: true }}
            onSuccess={(page) => {
                if (isRejectedSubmission(page.flash)) {
                    return;
                }

                drop();
                setScheduledAt('');
                setIsScheduling(false);
                attachments.clear();
                setPoll(null);

                if (onCommitted) {
                    onCommitted();
                } else {
                    focusBody(id);
                }
            }}
            data-test="commit-composer"
        >
            {({ processing, errors }) => (
                <fieldset disabled={issue.processing} className="contents">
                    <CommitComposerBody
                        key={draftVersion}
                        id={id}
                        isInDialog={isInDialog}
                        user={user}
                        stash={stash}
                        attachments={attachments}
                        linkCard={linkCard}
                        schedule={schedule}
                        poll={poll}
                        setPoll={setPoll}
                        commitRules={commitRules}
                        isSubmittable={isSubmittable}
                        processing={processing}
                        issue={issue}
                        errors={errors}
                        onKeyDown={onKeyDown}
                        drop={drop}
                    />
                </fieldset>
            )}
        </Form>
    );
}
