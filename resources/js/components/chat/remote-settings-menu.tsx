import { Link, router, usePage } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
import { toast } from 'sonner';
import { update } from '@/actions/App/Http/Controllers/UserRemotePreferencesController';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { edit } from '@/routes/user-profile';
import type { RemotesFrom } from '@/types';

/**
 * Who can push to the user straight away, and whether they share pulls. Changing either only affects
 * conversations started from now on.
 */
export function RemoteSettingsMenu() {
    const { auth } = usePage().props;

    if (auth.remotes === null) {
        return null;
    }

    const { from, pull_receipts: pullReceipts } = auth.remotes;

    const save = (preferences: { from: RemotesFrom; pull_receipts: boolean }) =>
        router.put(update.url(), preferences, {
            preserveScroll: true,
            preserveState: true,
            only: ['auth'],
            onError: () =>
                toast.error('Could not save your remote settings. Try again.'),
        });

    return (
        <DropdownMenu modal={false}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger
                        aria-label="Remote settings"
                        className="grid size-9 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground"
                        data-test="remote-settings"
                    >
                        <Settings2 className="size-[18px]" />
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent>Remote settings</TooltipContent>
            </Tooltip>
            <DropdownMenuContent align="end" className="w-72">
                <DropdownMenuLabel className="font-mono text-xs font-normal text-muted-foreground">
                    who can push to you
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={from}
                    onValueChange={(value) =>
                        save({
                            from: value as RemotesFrom,
                            pull_receipts: pullReceipts,
                        })
                    }
                >
                    <DropdownMenuRadioItem value="following">
                        People you follow
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="everyone">
                        Everyone
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
                <p className="px-2 pb-2 text-xs text-muted-foreground">
                    Anyone else lands in Fetched until you merge them. Requests
                    already fetched stay there.
                </p>
                <DropdownMenuSeparator />
                <DropdownMenuCheckboxItem
                    checked={pullReceipts}
                    onCheckedChange={(checked) =>
                        save({ from, pull_receipts: checked === true })
                    }
                    onSelect={(event) => event.preventDefault()}
                    data-test="pull-receipts"
                >
                    Share pulls
                </DropdownMenuCheckboxItem>
                <p className="px-2 pb-2 text-xs text-muted-foreground">
                    When off, nobody sees when you pull their messages, and you
                    don&apos;t see when they pull yours.
                </p>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={edit()}>Turn remotes off in settings</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
