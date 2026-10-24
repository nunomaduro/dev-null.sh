import { Form, usePage } from '@inertiajs/react';
import { Quote } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useRef, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
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
    ComposerPreviewBody,
    PreviewToggle,
    useComposerPreview,
} from '@/components/composer-preview';
import InputError from '@/components/input-error';
import { LinkCardPicker } from '@/components/link-card-picker';
import { MentionTextarea } from '@/components/mention-textarea';
import { QuotedCommit } from '@/components/quoted-commit';
import { StashLabel } from '@/components/stash-label';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { UserAvatar } from '@/components/user-avatar';
import { useMarkCommitDeleted } from '@/hooks/use-deleted-resources';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { useLinkCard } from '@/hooks/use-link-card';
import { moveCaretToEnd, useHasStash, useStash } from '@/hooks/use-stash';
import { useUser } from '@/hooks/use-user';
import { signIn } from '@/lib/auth';
import {
    bodyCharacterCount,
    maxBodyLength,
    maxRawBodyLength,
    minBodyLength,
} from '@/lib/commit';
import { hasModKey, useKeyLabels } from '@/lib/keyboard';
import { characterCount, cn, isRejectedSubmission } from '@/lib/utils';
import type { Commit } from '@/types';

export function CommitQuoteButton({
    commit,
    keyShortcut,
    className,
}: {
    commit: Commit;
    keyShortcut?: string;
    className?: string;
}) {
    const { auth } = usePage().props;
    const [isOpen, setIsOpen] = useState(false);
    const hasStash = useHasStash(auth.user?.id ?? null, `quote-${commit.id}`);

    if (commit.status !== 'approved' && commit.user_id !== auth.user?.id) {
        return null;
    }

    return (
        <>
            <ActionTooltip label="Quote" shortcut={keyShortcut}>
                <button
                    type="button"
                    onClick={() => {
                        if (auth.user === null) {
                            signIn();

                            return;
                        }

                        setIsOpen(true);
                    }}
                    aria-haspopup="dialog"
                    aria-keyshortcuts={keyShortcut}
                    className={cn(
                        'flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                        className,
                    )}
                    data-test="quote-button"
                >
                    <Quote className="size-4" />
                    <span className="sr-only sm:not-sr-only">Quote</span>
                    {hasStash && (
                        <span
                            title="You have an unsent quote here"
                            className="font-mono text-[11px] opacity-70"
                        >
                            {'stash@{0}'}
                        </span>
                    )}
                </button>
            </ActionTooltip>

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                {isOpen && (
                    <QuoteComposer
                        commit={commit}
                        onClose={() => setIsOpen(false)}
                    />
                )}
            </Dialog>
        </>
    );
}

function QuoteComposer({
    commit,
    onClose,
}: {
    commit: Commit;
    onClose: () => void;
}) {
    const { user } = useUser();
    const markCommitDeleted = useMarkCommitDeleted();
    const id = `quote-${commit.id}`;
    const {
        body,
        images,
        descriptions,
        isRestored,
        key: stashKey,
        updateBody,
        updateImages,
        drop,
    } = useStash(user.id, id);
    const attachments = useImageAttachments(
        images,
        descriptions,
        updateImages,
        stashKey,
    );
    const linkCard = useLinkCard(body);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const commitRules = useCommitRules();
    const keys = useKeyLabels();

    const isQuotable =
        (images.length > 0 || characterCount(body.trim()) >= minBodyLength) &&
        bodyCharacterCount(body) <= maxBodyLength;
    const preview = useComposerPreview({
        body,
        textareaRef,
        isSubmittable: isQuotable,
    });

    const submitOnModEnter = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (preview.handleShortcut(event)) {
            return;
        }

        if (
            event.key === 'Enter' &&
            hasModKey(event) &&
            isQuotable &&
            !attachments.isReading()
        ) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <DialogContent
            onEscapeKeyDown={(event) => {
                if (
                    document.querySelector(
                        '[data-mention-suggestions], [role="dialog"].yarl__root',
                    ) ||
                    (typeof Element !== 'undefined' &&
                        event.target instanceof Element &&
                        event.target.closest('[data-composer-preview]'))
                ) {
                    event.preventDefault();
                }
            }}
            onInteractOutside={(event) => {
                if (
                    typeof Element !== 'undefined' &&
                    event.target instanceof Element &&
                    event.target.closest(
                        '[data-mention-suggestions], [role="dialog"].yarl__root',
                    )
                ) {
                    event.preventDefault();
                }
            }}
            {...attachments.dropzone}
            className="gap-3 p-4 sm:max-w-xl sm:p-5"
            data-test="quote-composer"
        >
            {attachments.isDraggingFiles && (
                <ImageDropOverlay className="fixed" />
            )}
            <DialogTitle className="text-base">
                Quote @{commit.user.username}
            </DialogTitle>
            <DialogDescription className="sr-only">
                Write a commit of your own. This commit is embedded below it.
            </DialogDescription>

            <Form
                {...CommitController.store.form()}
                errorBag="quote"
                onBefore={() =>
                    !attachments.isReading() && commitRules.beforeSubmit()
                }
                transform={(data) => ({
                    ...data,
                    commit_rules_accepted: commitRules.hasAccepted(),
                    ...(images.length > 0 && {
                        images,
                        image_descriptions: descriptions,
                    }),
                })}
                options={{ preserveScroll: true }}
                onError={(errors) => {
                    if (errors.quoted_commit_id) {
                        markCommitDeleted(commit.id);
                        onClose();
                    }
                }}
                onSuccess={(page) => {
                    if (!isRejectedSubmission(page.flash)) {
                        attachments.clear();
                        drop();
                        onClose();
                    }
                }}
                className="flex min-w-0 gap-3"
            >
                {({ processing, errors }) => (
                    <>
                        <UserAvatar
                            user={user}
                            className="hidden size-10 sm:flex"
                        />

                        <div className="min-w-0 flex-1">
                            <input
                                type="hidden"
                                name="quoted_commit_id"
                                value={commit.id}
                            />
                            <label htmlFor={id} className="sr-only">
                                Your commit
                            </label>
                            <div className="grid">
                                <MentionTextarea
                                    ref={textareaRef}
                                    id={id}
                                    quote={commit.id}
                                    name="body"
                                    rows={1}
                                    autoFocus
                                    value={body}
                                    onValueChange={updateBody}
                                    onKeyDown={submitOnModEnter}
                                    onPaste={attachments.onPaste}
                                    onFocus={
                                        isRestored ? moveCaretToEnd : undefined
                                    }
                                    maxLength={maxRawBodyLength}
                                    placeholder="Add your take"
                                    autoComplete="off"
                                    aria-invalid={
                                        errors.body ||
                                        errors.link_preview_url ||
                                        errors.quoted_commit_id
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={`${id}-count`}
                                    className={cn(
                                        'col-start-1 row-start-1 block field-sizing-content max-h-[40dvh] min-h-10 w-full resize-none overflow-x-hidden overflow-y-auto bg-transparent py-1.5 text-[17px] leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:outline-none',
                                        { invisible: preview.isPreviewing },
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

                            <InputError
                                message={
                                    errors.body ??
                                    errors.link_preview_url ??
                                    errors.quoted_commit_id
                                }
                            />

                            <CommitRulesDialog
                                rules={commitRules}
                                textarea={textareaRef}
                            />

                            {!preview.isPreviewing && (
                                <ImagePickerPreviews
                                    images={images}
                                    descriptions={descriptions}
                                    onDescribe={attachments.describe}
                                    onRemove={attachments.remove}
                                />
                            )}
                            <InputError
                                message={
                                    attachments.error ?? imageErrorFrom(errors)
                                }
                                className="mt-1"
                            />

                            <QuotedCommit commit={commit} isPreview />

                            <div className="sticky -bottom-4 -mb-4 flex flex-wrap items-center justify-end gap-x-3 gap-y-2 bg-background pt-3 pb-4 sm:-bottom-5 sm:-mb-5 sm:pb-5">
                                <div className="mr-auto flex min-w-0 items-center gap-2">
                                    <ImagePickerButton
                                        count={images.length}
                                        disabled={processing}
                                        reading={attachments.reading}
                                        onPick={attachments.pick}
                                        className="size-8"
                                    />
                                    <PreviewToggle
                                        preview={preview}
                                        className="-ml-2 size-8"
                                    />
                                    <LinkCardPicker
                                        linkCard={linkCard}
                                        disabled={processing}
                                        className="-ml-1"
                                        labelClassName="max-sm:sr-only"
                                    />
                                    {isRestored && (
                                        <StashLabel
                                            onDrop={() => {
                                                attachments.clear();
                                                drop();
                                                linkCard.reset();
                                                document
                                                    .getElementById(id)
                                                    ?.focus();
                                            }}
                                            className="text-[13px]"
                                        />
                                    )}
                                </div>
                                <BodyCounter
                                    id={`${id}-count`}
                                    body={body}
                                    className="text-[13px]"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        attachments.reading ||
                                        !isQuotable
                                    }
                                    title={`Quote (${keys.combo(keys.mod, keys.enter)})`}
                                    data-test="submit-quote-button"
                                >
                                    {processing ? 'Quoting…' : 'Quote'}
                                </Button>
                            </div>
                        </div>
                    </>
                )}
            </Form>
        </DialogContent>
    );
}
