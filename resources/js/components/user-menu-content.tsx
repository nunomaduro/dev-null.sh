import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bookmark,
    LogOut,
    ScrollText,
    Settings,
    Shield,
    UserRound,
} from 'lucide-react';
import BookmarkController from '@/actions/App/Http/Controllers/BookmarkController';
import OperationsController from '@/actions/App/Http/Controllers/OperationsController';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { clearStashes } from '@/hooks/use-stash';
import { logout } from '@/routes';
import { show as showPrivacy } from '@/routes/privacy';
import { show as showTerms } from '@/routes/terms';
import { show } from '@/routes/user';
import { edit } from '@/routes/user-profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const { auth } = usePage().props;
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        clearStashes(user.id);
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showUsername={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={BookmarkController.index()}
                        onClick={cleanup}
                    >
                        <Bookmark className="mr-2" />
                        Saved
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={show(user)}
                        prefetch
                        onClick={cleanup}
                    >
                        <UserRound className="mr-2" />
                        Profile
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Settings
                    </Link>
                </DropdownMenuItem>
                {auth.isAdmin && (
                    <DropdownMenuItem asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href={OperationsController.show()}
                            onClick={cleanup}
                        >
                            <Activity className="mr-2" />
                            Operations
                        </Link>
                    </DropdownMenuItem>
                )}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={showTerms()}
                        onClick={cleanup}
                    >
                        <ScrollText className="mr-2" />
                        Terms
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={showPrivacy()}
                        onClick={cleanup}
                    >
                        <Shield className="mr-2" />
                        Privacy
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
