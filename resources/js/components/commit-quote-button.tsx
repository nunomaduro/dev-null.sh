import { Form, usePage } from '@inertiajs/react';
import { Quote } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import { BodyCounter } from '@/components/body-counter';
import {
    ImageDropOverlay,
    ImagePickerButton,
    ImagePickerPreviews,
    imageErrorFrom,
} from '@/components/commit-image-picker';
import { GiphyAttachmentPreview } from '@/components/giphy-attachment-preview';
import { GiphyPickerButton } from '@/components/giphy-picker-button';
import InputError from '@/components/input-error';
import { MentionTextarea } from '@/components/mention-textarea';
import { QuotedCommit } from '@/components/quoted-commit';
import { SecretWarning } from '@/components/secret-warning';
import { StashLabel } from '@/components/stash-label';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { UserAvatar } from '@/components/user-avatar';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { useSecretWarning } from '@/hooks/use-secret-warning';
import { moveCaretToEnd, useHasStash, useStash } from '@/hooks/use-stash';
import { useUser } from '@/hooks/use-user';
import { signIn } from '@/lib/auth';
import { maxBodyLength, minBodyLength } from '@/lib/commit';
import { giphyFormFields } from '@/lib/giphy';
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
                title={keyShortcut ? `Quote (${keyShortcut})` : 'Quote'}
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
    const id = `quote-${commit.id}`;
    const {
        body,
        images,
        descriptions,
        gif,
        isRestored,
        updateBody,
        updateImages,
        updateGif,
        drop,
    } = useStash(user.id, id);
    const attachments = useImageAttachments(images, descriptions, updateImages);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const secrets = useSecretWarning(body);
    const keys = useKeyLabels();

    useEffect(() => {
        if (attachments.images.length > 0 && gif !== null) {
            updateGif(null);
        }
    }, [attachments.images.length, gif, updateGif]);

    const isQuotable =
        images.length > 0 || characterCount(body.trim()) >= minBodyLength;

    const submitOnModEnter = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && hasModKey(event) && isQuotable) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <DialogContent
            onEscapeKeyDown={(event) => {
                if (document.querySelector('[data-mention-suggestions]')) {
                    event.preventDefault();
                }
            }}
            onInteractOutside={(event) => {
                if (
                    event.target instanceof Element &&
                    event.target.closest('[data-mention-suggestions]')
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
                onBefore={secrets.beforeSubmit}
                transform={(data) =>
                    gif !== null
                        ? { ...data, ...giphyFormFields(gif) }
                        : images.length > 0
                          ? {
                                ...data,
                                images,
                                image_descriptions: descriptions,
                            }
                          : data
                }
                options={{ preserveScroll: true }}
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
                            <MentionTextarea
                                ref={textareaRef}
                                id={id}
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
                                maxLength={maxBodyLength}
                                placeholder="Add your take"
                                autoComplete="off"
                                aria-invalid={
                                    errors.body || errors.quoted_commit_id
                                        ? true
                                        : undefined
                                }
                                aria-describedby={`${id}-count`}
                                className="block field-sizing-content max-h-[40dvh] min-h-10 w-full resize-none overflow-x-hidden overflow-y-auto bg-transparent py-1.5 text-[17px] leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:outline-none"
                            />

                            <InputError
                                message={errors.body ?? errors.quoted_commit_id}
                            />

                            <SecretWarning
                                body={body}
                                findings={secrets.findings}
                                textarea={textareaRef}
                                onChange={updateBody}
                                confirming={secrets.confirming}
                                onConfirmingChange={secrets.setConfirming}
                                onApprove={secrets.approve}
                            />

                            <ImagePickerPreviews
                                images={images}
                                descriptions={descriptions}
                                onDescribe={attachments.describe}
                                onRemove={attachments.remove}
                            />
                            <GiphyAttachmentPreview
                                gif={gif}
                                onRemove={() => updateGif(null)}
                            />
                            <InputError
                                message={
                                    attachments.error ?? imageErrorFrom(errors)
                                }
                                className="mt-1"
                            />

                            <QuotedCommit commit={commit} isPreview />

                            <div className="sticky -bottom-4 -mb-4 flex items-center justify-end gap-3 bg-background pt-3 pb-4 sm:-bottom-5 sm:-mb-5 sm:pb-5">
                                <div className="mr-auto flex items-center gap-2">
                                    <ImagePickerButton
                                        count={images.length}
                                        disabled={processing}
                                        onPick={(files) => {
                                            updateGif(null);
                                            attachments.attach(files);
                                        }}
                                        className="size-8"
                                    />
                                    <GiphyPickerButton
                                        disabled={processing}
                                        hasGif={gif !== null}
                                        onPick={(picked) => {
                                            attachments.clear();
                                            updateGif(picked);
                                        }}
                                        className="-ml-2 size-8"
                                    />
                                    {isRestored && (
                                        <StashLabel
                                            onDrop={() => {
                                                attachments.clear();
                                                drop();
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
                                    disabled={processing || !isQuotable}
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
