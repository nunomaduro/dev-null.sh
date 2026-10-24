import { Form, router } from '@inertiajs/react';
import { Radio } from 'lucide-react';
import { useState } from 'react';
import UserLiveController from '@/actions/App/Http/Controllers/UserLiveController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

/**
 * Mirrors `User::MAX_LIVE_HOURS`, the longest a stream can be shown as live.
 */
const maxHours = 12;

const defaultHours = 2;

/**
 * The go-live form (`PUT settings/live`): a stream URL and how many hours to show it as live, from the profile menu
 * or the rail's You slot.
 */
export function GoLiveDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [hours, setHours] = useState(defaultHours);
    const hoursLabel = hours === 1 ? '1 hour' : `${hours} hours`;
    const filled = ((hours - 1) / (maxHours - 1)) * 100;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Go live</DialogTitle>
                <DialogDescription>
                    Share your YouTube or Twitch stream and your avatar gets a
                    live marker that sends people to it, for as long as you
                    choose.
                </DialogDescription>

                <Form
                    {...UserLiveController.update.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        onOpenChange(false);
                        setHours(defaultHours);
                        router.flushAll();
                    }}
                    resetOnSuccess
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="live-url">Stream link</Label>
                                <input
                                    id="live-url"
                                    type="url"
                                    name="url"
                                    required
                                    autoFocus
                                    placeholder="https://www.youtube.com/live/… or https://twitch.tv/…"
                                    className="flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                                    data-test="live-url-input"
                                />
                                <InputError message={errors.url} />
                            </div>

                            <div className="grid gap-3">
                                <div className="flex items-baseline justify-between gap-4">
                                    <Label htmlFor="live-hours">For</Label>
                                    <output
                                        htmlFor="live-hours"
                                        className="min-w-[8ch] text-right font-mono text-[13px] text-muted-foreground tabular-nums"
                                    >
                                        {hoursLabel}
                                    </output>
                                </div>
                                <input
                                    id="live-hours"
                                    type="range"
                                    name="hours"
                                    min={1}
                                    max={maxHours}
                                    value={hours}
                                    onChange={(event) =>
                                        setHours(
                                            event.currentTarget.valueAsNumber,
                                        )
                                    }
                                    aria-valuetext={hoursLabel}
                                    style={{
                                        background: `linear-gradient(to right, var(--primary) ${filled}%, var(--muted) ${filled}%)`,
                                    }}
                                    className="h-1.5 w-full cursor-pointer appearance-none rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&::-moz-range-thumb]:size-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-2 [&::-moz-range-thumb]:border-primary [&::-moz-range-thumb]:bg-background [&::-moz-range-thumb]:shadow-sm [&::-webkit-slider-thumb]:size-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-primary [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm [&::-webkit-slider-thumb]:transition-transform active:[&::-webkit-slider-thumb]:scale-110 motion-reduce:[&::-webkit-slider-thumb]:transition-none"
                                    data-test="live-hours-slider"
                                />
                                <div
                                    aria-hidden
                                    className="flex justify-between font-mono text-xs text-muted-foreground"
                                >
                                    <span>1h</span>
                                    <span>{maxHours}h</span>
                                </div>
                                <InputError message={errors.hours} />
                            </div>

                            <DialogFooter className="mt-2 gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="go-live-button"
                                >
                                    <Radio />
                                    Go live
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Ends your stream (`DELETE settings/live`) and flushes cached pages so no live marker lingers; `isEnding` is true
 * while the request runs.
 */
export function useEndStream(): { endStream: () => void; isEnding: boolean } {
    const [isEnding, setIsEnding] = useState(false);

    const endStream = () =>
        router.visit(UserLiveController.destroy(), {
            preserveScroll: true,
            onStart: () => setIsEnding(true),
            onFinish: () => setIsEnding(false),
            onSuccess: () => router.flushAll(),
        });

    return { endStream, isEnding };
}
