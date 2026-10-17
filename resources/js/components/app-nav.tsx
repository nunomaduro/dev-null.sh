import { Deferred, Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Bookmark,
    GitCommitVertical,
    Globe,
    Search,
    Settings2,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import BookmarkController from '@/actions/App/Http/Controllers/BookmarkController';
import LogController from '@/actions/App/Http/Controllers/LogController';
import ModerationController from '@/actions/App/Http/Controllers/ModerationController';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import AppLogo from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { Skeleton } from '@/components/ui/skeleton';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserAvatar } from '@/components/user-avatar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { show } from '@/routes/user';
import { edit } from '@/routes/user-profile';
import type { NavItem } from '@/types';

function useNavItems(): (NavItem & { isActive: boolean })[] {
    const { auth, hasUnmoderatedCommits } = usePage().props;
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    const items: NavItem[] = [
        {
            title: 'Log',
            href: LogController.show('following'),
            icon: GitCommitVertical,
        },
        {
            title: 'Everyone',
            href: LogController.show('everyone'),
            icon: Globe,
        },
        {
            title: 'Notifications',
            href: NotificationController.index(),
            icon: Bell,
            badge: auth.unreadNotificationsCount,
            prefetch: false,
        },
        {
            title: 'Profile',
            href: show(auth.user),
            icon: UserRound,
        },
        {
            title: 'Saved',
            href: BookmarkController.index(),
            icon: Bookmark,
            prefetch: false,
        },
        ...(auth.isAdmin
            ? [
                  {
                      title: 'Moderation',
                      href: ModerationController.show(),
                      icon: ShieldCheck,
                      hasIndicator: hasUnmoderatedCommits,
                  },
              ]
            : []),
        {
            title: 'Settings',
            href: edit(),
            icon: Settings2,
        },
    ];

    return items.map((item) => ({
        ...item,
        isActive:
            item.title === 'Settings'
                ? isCurrentOrParentUrl('/settings')
                : isCurrentUrl(item.href),
    }));
}

function NavIcon({
    item,
    indicatorClassName,
}: {
    item: NavItem & { isActive: boolean };
    indicatorClassName?: string;
}) {
    if (!item.icon) {
        return null;
    }

    const count = item.isActive ? 0 : (item.badge ?? 0);

    return (
        <span className="relative">
            <item.icon className="size-5" />
            {item.hasIndicator && (
                <span
                    className={cn(
                        'absolute -top-0.5 -right-0.5 size-2 rounded-full bg-primary',
                        indicatorClassName,
                    )}
                    data-test="notification-indicator"
                />
            )}
            {count > 0 && (
                <span className="absolute -top-1.5 -right-2 min-w-4 rounded-full bg-primary px-1 text-center text-[10px] leading-4 font-semibold text-primary-foreground tabular-nums">
                    {count > 99 ? '99+' : count}
                    <span className="sr-only"> unread</span>
                </span>
            )}
        </span>
    );
}

export function AppNav({ onSearch }: { onSearch: () => void }) {
    const { auth, onlineDevelopersCount } = usePage().props;
    const items = useNavItems();

    return (
        <header className="sticky top-0 hidden h-svh w-20 shrink-0 flex-col items-center py-6 md:flex lg:w-64 lg:items-stretch lg:px-4">
            <Link
                href={LogController.show('following')}
                className="mb-8 rounded-md px-3 lg:px-3"
                aria-label="devnull, go to your log"
            >
                <AppLogoIcon className="size-7 fill-current lg:hidden" />
                <AppLogo className="hidden text-[1.75rem] font-bold tracking-[-0.03em] lg:inline-flex" />
                <span className="mt-1 hidden text-sm text-muted-foreground lg:block">
                    a social network for devs
                </span>
                <span
                    className="mt-2 hidden items-center gap-2 font-mono text-xs text-muted-foreground lg:flex"
                    data-test="nav-online-developers"
                >
                    <span
                        aria-hidden
                        className="size-2 rounded-full bg-emerald-500 motion-safe:animate-pulse"
                    />
                    <Deferred
                        data="onlineDevelopersCount"
                        fallback={<Skeleton className="h-3.5 w-24" />}
                    >
                        <span>
                            {onlineDevelopersCount}{' '}
                            {onlineDevelopersCount === 1
                                ? 'developer'
                                : 'developers'}{' '}
                            online
                        </span>
                    </Deferred>
                </span>
            </Link>

            <nav aria-label="Main" className="flex flex-col gap-1">
                {items.map((item) => (
                    <Link
                        key={item.title}
                        href={item.href}
                        prefetch={item.prefetch ?? true}
                        aria-current={item.isActive ? 'page' : undefined}
                        className={cn(
                            'flex items-center gap-3 rounded-lg p-3 text-[15px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                            {
                                'bg-primary-soft text-primary hover:bg-primary-soft hover:text-primary':
                                    item.isActive,
                            },
                        )}
                    >
                        <NavIcon item={item} indicatorClassName="lg:hidden" />
                        <span className="sr-only lg:not-sr-only">
                            {item.title}
                        </span>
                        {item.hasIndicator && (
                            <span
                                className="ml-auto hidden size-2 rounded-full bg-primary lg:inline-block"
                                data-test="notification-indicator"
                            />
                        )}
                    </Link>
                ))}
                <button
                    type="button"
                    onClick={onSearch}
                    className="flex items-center gap-3 rounded-lg p-3 text-[15px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground xl:hidden"
                >
                    <Search className="size-5" />
                    <span className="sr-only lg:not-sr-only">Search</span>
                    <kbd className="ml-auto hidden rounded border border-b-2 bg-muted px-1.5 font-mono text-xs lg:block">
                        ⌘K
                    </kbd>
                </button>
            </nav>

            <DropdownMenu>
                <DropdownMenuTrigger
                    className="mt-auto flex items-center gap-3 rounded-lg p-2 text-left transition-colors hover:bg-accent data-[state=open]:bg-accent"
                    data-test="user-menu-button"
                >
                    <UserAvatar user={auth.user} className="size-9" />
                    <span className="hidden min-w-0 flex-1 lg:block">
                        <span className="block truncate text-sm font-medium">
                            {auth.user.name}
                        </span>
                        <span className="block truncate font-mono text-xs text-muted-foreground">
                            @{auth.user.username}
                        </span>
                    </span>
                </DropdownMenuTrigger>
                <DropdownMenuContent className="w-60" side="top" align="start">
                    <UserMenuContent user={auth.user} />
                </DropdownMenuContent>
            </DropdownMenu>
        </header>
    );
}

export function MobileHeader({ onSearch }: { onSearch: () => void }) {
    const { auth } = usePage().props;

    return (
        <div className="flex h-14 items-center justify-between border-b px-4 md:hidden">
            <Link
                href={LogController.show('following')}
                aria-label="devnull, go to your log"
            >
                <AppLogo className="text-lg" />
            </Link>

            <div className="flex items-center gap-1">
                <button
                    type="button"
                    onClick={onSearch}
                    aria-label="Search developers"
                    className="rounded-full p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Search className="size-5" />
                </button>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        className="rounded-full"
                        aria-label="Account menu"
                    >
                        <UserAvatar user={auth.user} className="size-8" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-60" align="end">
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    );
}

export function MobileNav() {
    const items = useNavItems().filter((item) => item.title !== 'Saved');

    return (
        <nav
            aria-label="Main"
            className="fixed inset-x-0 bottom-0 z-20 flex border-t bg-card pb-[env(safe-area-inset-bottom)] md:hidden"
        >
            {items.map((item) => (
                <Link
                    key={item.title}
                    href={item.href}
                    prefetch={item.prefetch ?? true}
                    aria-current={item.isActive ? 'page' : undefined}
                    className={cn(
                        'flex flex-1 flex-col items-center gap-1 py-2.5 text-xs font-medium text-muted-foreground',
                        { 'text-primary': item.isActive },
                    )}
                >
                    <NavIcon item={item} />
                    {item.title}
                </Link>
            ))}
        </nav>
    );
}
