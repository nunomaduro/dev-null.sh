import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Star,
    GitCommitVertical,
    History,
    MessagesSquare,
    PanelLeftClose,
    PanelLeftOpen,
    Search,
    Settings2,
    ShieldCheck,
    SquarePen,
    UserRound,
} from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { useLayoutEffect, useRef, useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import LogController from '@/actions/App/Http/Controllers/LogController';
import ModerationController from '@/actions/App/Http/Controllers/ModerationController';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import OperationsController from '@/actions/App/Http/Controllers/OperationsController';
import StarredCommitController from '@/actions/App/Http/Controllers/StarredCommitController';
import AppLogo from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { ChangelogDialog } from '@/components/changelog-dialog';
import { GitHubIcon } from '@/components/github-icon';
import { LogoMenu } from '@/components/logo-menu';
import { MobileMenuIcon } from '@/components/mobile-menu-icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserAvatar } from '@/components/user-avatar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useChangelog } from '@/hooks/use-changelog';
import { useUnreadMessages } from '@/hooks/use-chat';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useMediaQuery } from '@/hooks/use-media-query';
import { useNavOpen } from '@/hooks/use-nav-open';
import { useKeyLabels } from '@/lib/keyboard';
import { browserTimeZone, cn, toUrl } from '@/lib/utils';
import { home, login } from '@/routes';
import { show } from '@/routes/user';
import { edit } from '@/routes/user-profile';
import type { Log, NavItem, Profile } from '@/types';

const navLabelClassName =
    'max-w-0 overflow-hidden opacity-0 transition-[max-width,opacity] duration-300 ease-[cubic-bezier(0.33,1,0.68,1)] nav-wide:max-w-40 nav-wide:opacity-100 motion-reduce:transition-none';

/**
 * Guests see the same items, each taking them to sign in.
 */
function useNavItems(): (NavItem & { isActive: boolean })[] {
    const { auth, hasUnmoderatedCommits } = usePage().props;
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { user, log } = auth;
    const unreadMessages = useUnreadMessages();

    const hrefFor = (
        href: (user: Profile, log: Log) => NavItem['href'],
    ): NavItem['href'] =>
        user === null || log === null ? login() : href(user, log);

    const items: NavItem[] = [
        {
            title: 'Log',
            href: log === null ? home() : LogController.show(log),
            icon: GitCommitVertical,
            prefetch: false,
        },
        {
            title: 'Notifications',
            href: hrefFor(() => NotificationController.index()),
            icon: Bell,
            badge: auth.unreadNotificationsCount,
            prefetch: false,
        },
        ...(auth.remotes !== null
            ? [
                  {
                      title: 'Remotes',
                      description: 'Direct messages',
                      href: ConversationController.index(),
                      icon: MessagesSquare,
                      badge: unreadMessages,
                      prefetch: false,
                  },
              ]
            : []),
        {
            title: 'Profile',
            href: hrefFor((user) => show(user)),
            icon: UserRound,
        },
        {
            title: 'Starred',
            href: hrefFor(() => StarredCommitController.index()),
            icon: Star,
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
                  {
                      title: 'Operations',
                      href: OperationsController.show(),
                      icon: Activity,
                      prefetch: false,
                  },
              ]
            : []),
        {
            title: 'Settings',
            href: hrefFor(() => edit()),
            icon: Settings2,
        },
    ];

    return items.map((item) => ({
        ...item,
        isActive:
            user !== null &&
            (item.title === 'Settings'
                ? isCurrentOrParentUrl('/settings')
                : item.title === 'Log'
                  ? isCurrentOrParentUrl('/log')
                  : item.title === 'Remotes'
                    ? isCurrentOrParentUrl('/remotes')
                    : item.title === 'Operations'
                      ? isCurrentOrParentUrl('/operations')
                      : isCurrentUrl(item.href)),
    }));
}

/**
 * Signing in leaves for GitHub, which an Inertia visit cannot follow, so guests get a plain link instead.
 */
function NavLink({
    item,
    className,
    iconsOnly = false,
    children,
}: {
    item: NavItem & { isActive: boolean };
    className: string;
    iconsOnly?: boolean;
    children: ReactNode;
}) {
    const { auth } = usePage().props;
    const title = iconsOnly ? undefined : item.description;

    if (auth.user === null) {
        return (
            <NavTooltip label={iconsOnly ? item.title : null}>
                <a href={toUrl(item.href)} title={title} className={className}>
                    {children}
                </a>
            </NavTooltip>
        );
    }

    return (
        <NavTooltip label={iconsOnly ? item.title : null}>
            <Link
                href={item.href}
                prefetch={item.prefetch ?? true}
                viewTransition
                aria-current={item.isActive ? 'page' : undefined}
                title={title}
                className={className}
            >
                {children}
            </Link>
        </NavTooltip>
    );
}

/**
 * The tooltip stays in the tree whether or not it can show, so toggling the navigation keeps each link and its label
 * fades instead of being replaced.
 */
function NavTooltip({
    label,
    children,
}: {
    label: string | null;
    children: ReactElement;
}) {
    const [isOpen, setIsOpen] = useState(false);

    if (label === null && isOpen) {
        setIsOpen(false);
    }

    return (
        <Tooltip
            open={label !== null && isOpen}
            onOpenChange={(open) => setIsOpen(label !== null && open)}
        >
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipContent side="right">{label}</TooltipContent>
        </Tooltip>
    );
}

function SignInButton({ className }: { className?: string }) {
    return (
        <Button asChild className={className}>
            <a href={login().url} data-test="nav-login-button">
                <GitHubIcon className="size-4" />
                <span className={navLabelClassName}>Sign in with GitHub</span>
            </a>
        </Button>
    );
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
                <span className="absolute -top-1.5 -right-2 min-w-4 rounded-sm bg-primary px-1 text-center text-[10px] leading-4 font-semibold text-primary-foreground tabular-nums">
                    {count > 99 ? '99+' : count}
                    <span className="sr-only"> unread</span>
                </span>
            )}
        </span>
    );
}

export function AppNav({
    onSearch,
    onCompose,
}: {
    onSearch: () => void;
    onCompose: () => void;
}) {
    const { auth } = usePage().props;
    const { isNavOpen: sidebarOpen, isLayoutOpen, setNavOpen } = useNavOpen();
    const items = useNavItems();
    const keys = useKeyLabels();

    const iconsOnly = !(useMediaQuery('(min-width: 64rem)') && isLayoutOpen);

    return (
        <header className="flex h-full w-full flex-col items-stretch overflow-hidden px-3.5 py-6 whitespace-nowrap">
            <LogoMenu>
                <Link
                    href={
                        auth.log === null
                            ? home()
                            : LogController.show(auth.log)
                    }
                    viewTransition
                    className="mb-8 grid shrink-0 grid-rows-[36px_20px] gap-y-1 rounded-md px-3"
                    aria-label={
                        auth.log === null
                            ? '/dev/null, go to the home page'
                            : '/dev/null, go to your log'
                    }
                >
                    <AppLogoIcon className="col-start-1 row-start-1 size-7 self-center fill-current opacity-100 transition-opacity duration-300 motion-reduce:transition-none nav-wide:opacity-0" />
                    <AppLogo className="col-start-1 row-start-1 w-max max-w-0 overflow-hidden text-[1.75rem] font-bold tracking-[-0.03em] opacity-0 transition-[max-width,opacity] duration-300 ease-[cubic-bezier(0.33,1,0.68,1)] motion-reduce:transition-none nav-wide:max-w-52 nav-wide:opacity-100" />
                    <span className="col-start-1 row-start-2 max-w-0 overflow-hidden text-sm leading-5 text-muted-foreground opacity-0 transition-[max-width,opacity] duration-300 ease-[cubic-bezier(0.33,1,0.68,1)] motion-reduce:transition-none nav-wide:max-w-52 nav-wide:opacity-100">
                        a social network for devs
                    </span>
                </Link>
            </LogoMenu>

            <nav
                aria-label="Main"
                className="flex min-h-0 flex-1 scrollbar-none flex-col gap-1 overflow-y-auto [&>*]:shrink-0"
            >
                {items.map((item) => (
                    <NavLink
                        key={item.title}
                        item={item}
                        iconsOnly={iconsOnly}
                        className={cn(
                            'relative isolate block h-11 rounded-lg py-3 pr-3 pl-4 text-[15px] leading-5 font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                            {
                                'text-primary hover:bg-transparent hover:text-primary':
                                    item.isActive,
                            },
                        )}
                    >
                        {item.isActive && (
                            <span
                                aria-hidden
                                className="absolute inset-0 -z-10 rounded-lg bg-primary-soft [view-transition-name:nav-active-item]"
                            />
                        )}
                        <span
                            className="flex items-center gap-3 [view-transition-class:indicator-label]"
                            style={{
                                viewTransitionName: `nav-${item.title.toLowerCase()}`,
                            }}
                        >
                            <NavIcon
                                item={item}
                                indicatorClassName="nav-wide:hidden"
                            />
                            <span className={navLabelClassName}>
                                {item.title}
                            </span>
                            {item.hasIndicator && (
                                <span
                                    className="ml-auto hidden size-2 rounded-full bg-primary nav-wide:inline-block"
                                    data-test="notification-indicator"
                                />
                            )}
                        </span>
                    </NavLink>
                ))}
                <NavTooltip label={iconsOnly ? 'Search' : null}>
                    <button
                        type="button"
                        onClick={onSearch}
                        className="flex h-11 items-center gap-3 rounded-lg py-3 pr-3 pl-4 text-[15px] leading-5 font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground xl:hidden"
                    >
                        <Search className="size-6 shrink-0" />
                        <span className={navLabelClassName}>Search</span>
                        <kbd className="ml-auto hidden rounded border border-b-2 bg-muted px-1.5 font-mono text-xs nav-wide:block">
                            {keys.combo(keys.mod, 'K')}
                        </kbd>
                    </button>
                </NavTooltip>
                <NavTooltip label={iconsOnly ? 'Write a commit (C)' : null}>
                    <button
                        type="button"
                        onClick={onCompose}
                        aria-label="Write a commit"
                        aria-keyshortcuts="c"
                        title={iconsOnly ? undefined : 'Write a commit (C)'}
                        className="flex h-11 items-center gap-3 rounded-lg bg-primary py-3 pr-3 pl-4 text-[15px] leading-5 font-medium text-primary-foreground transition-colors hover:bg-primary-hover focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        data-test="nav-commit-button"
                    >
                        <SquarePen className="size-5 shrink-0" />
                        <span className={navLabelClassName}>Commit</span>
                    </button>
                </NavTooltip>
                <NavTooltip
                    label={
                        iconsOnly ? (sidebarOpen ? 'Collapse' : 'Expand') : null
                    }
                >
                    <button
                        type="button"
                        onClick={() => setNavOpen(!sidebarOpen)}
                        aria-label={
                            sidebarOpen
                                ? 'Collapse navigation'
                                : 'Expand navigation'
                        }
                        aria-expanded={sidebarOpen}
                        title={
                            iconsOnly
                                ? undefined
                                : sidebarOpen
                                  ? 'Collapse navigation'
                                  : 'Expand navigation'
                        }
                        className="hidden h-11 items-center gap-3 rounded-lg py-3 pr-3 pl-4 text-[15px] leading-5 font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground lg:flex"
                        data-test="nav-collapse-button"
                    >
                        {sidebarOpen ? (
                            <PanelLeftClose className="size-5 shrink-0" />
                        ) : (
                            <PanelLeftOpen className="size-5 shrink-0" />
                        )}
                        <span className={navLabelClassName}>
                            {sidebarOpen ? 'Collapse' : 'Expand'}
                        </span>
                    </button>
                </NavTooltip>
            </nav>

            {auth.user === null ? (
                <SignInButton className="mt-auto size-[52px] shrink-0 gap-0 rounded-lg nav-wide:w-auto nav-wide:gap-2" />
            ) : (
                <DropdownMenu>
                    <DropdownMenuTrigger
                        className="mt-auto flex shrink-0 items-center gap-3 rounded-lg p-2 text-left transition-colors hover:bg-accent data-[state=open]:bg-accent"
                        data-test="user-menu-button"
                    >
                        <UserAvatar user={auth.user} className="size-9" />
                        <span className="max-w-0 min-w-0 flex-1 overflow-hidden opacity-0 transition-[max-width,opacity] duration-300 ease-[cubic-bezier(0.33,1,0.68,1)] motion-reduce:transition-none nav-wide:max-w-40 nav-wide:opacity-100">
                            <span className="block truncate text-sm font-medium">
                                {auth.user.name}
                            </span>
                            <span className="block truncate font-mono text-xs text-muted-foreground">
                                @{auth.user.username}
                            </span>
                        </span>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className={
                            iconsOnly
                                ? 'w-60'
                                : 'w-(--radix-dropdown-menu-trigger-width)'
                        }
                        side="top"
                        align="start"
                    >
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </header>
    );
}

export function MobileHeader({ onSearch }: { onSearch: () => void }) {
    const { auth } = usePage().props;

    return (
        <div className="flex h-14 items-center justify-between border-b px-4 md:hidden">
            <Link
                href={auth.log === null ? home() : LogController.show(auth.log)}
                viewTransition
                aria-label={
                    auth.log === null
                        ? '/dev/null, go to the home page'
                        : '/dev/null, go to your log'
                }
            >
                <AppLogo className="text-lg" />
            </Link>

            <div className="flex items-center gap-1">
                <button
                    type="button"
                    onClick={onSearch}
                    aria-label="Search"
                    className="rounded-md p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Search className="size-5" />
                </button>
                {auth.user === null ? (
                    <Button size="sm" className="h-8" asChild>
                        <a href={login().url} data-test="nav-login-button">
                            <GitHubIcon className="size-4" />
                            Sign in
                        </a>
                    </Button>
                ) : (
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
                )}
            </div>
        </div>
    );
}

export function MobileCommitButton({
    isHidden,
    onCompose,
}: {
    isHidden: boolean;
    onCompose: () => void;
}) {
    return (
        <Button
            type="button"
            onClick={onCompose}
            inert={isHidden}
            aria-label="Write a commit"
            title="Write a commit"
            className={cn(
                'absolute right-4 bottom-full mb-4 size-14 rounded-lg shadow-lg transition-opacity duration-200 [view-transition-name:mobile-commit-button] md:hidden',
                { 'pointer-events-none opacity-0': isHidden },
            )}
            data-test="mobile-commit-button"
        >
            <SquarePen className="size-6" />
        </Button>
    );
}

/**
 * Shares its real height, safe area included, as `--mobile-nav-height`, so full-screen pages can end right above it.
 */
export function MobileNav({ children }: { children?: ReactNode }) {
    const { auth } = usePage().props;
    const timeZone = auth.user?.timezone ?? browserTimeZone();
    const {
        changelog,
        hasFailed,
        isLoadingOlder,
        hasOlderFailed,
        load,
        loadOlder,
    } = useChangelog(timeZone);
    const [isChangelogOpen, setIsChangelogOpen] = useState(false);
    const allItems = useNavItems();
    const menuItems = allItems.filter((item) =>
        ['Profile', 'Settings', 'Operations'].includes(item.title),
    );
    const items = allItems.filter(
        (item) =>
            item.title !== 'Starred' &&
            !menuItems.some((menuItem) => menuItem.title === item.title),
    );
    const nav = useRef<HTMLElement>(null);

    useLayoutEffect(() => {
        const element = nav.current;

        if (element === null) {
            return;
        }

        const root = document.documentElement;
        // Hidden while the keyboard is open, it keeps its height, so toasts above it do not drop onto it on the way back.
        const updateHeight = () => {
            const { height } = element.getBoundingClientRect();

            if (height > 0) {
                root.style.setProperty('--mobile-nav-height', `${height}px`);
            }
        };
        const observer = new ResizeObserver(updateHeight);

        updateHeight();
        observer.observe(element, { box: 'border-box' });

        return () => {
            observer.disconnect();
            root.style.removeProperty('--mobile-nav-height');
        };
    }, []);

    return (
        <nav
            ref={nav}
            aria-label="Main"
            data-video-dock-obstacle
            className="fixed inset-x-0 bottom-0 z-20 flex border-t bg-card pb-[env(safe-area-inset-bottom)] [view-transition-name:mobile-nav] md:hidden [:root[data-keyboard-open]_&]:hidden"
        >
            {children}
            {items.map((item) => (
                <NavLink
                    key={item.title}
                    item={item}
                    className={cn(
                        'flex min-w-0 flex-1 flex-col items-center gap-1 py-1 text-[11px] font-medium tracking-tight text-muted-foreground',
                        { 'text-primary': item.isActive },
                    )}
                >
                    <span className="relative isolate flex h-8 w-14 max-w-full items-center justify-center">
                        {item.isActive && (
                            <span
                                aria-hidden
                                className="absolute inset-0 -z-10 rounded-lg bg-primary-soft [view-transition-name:mobile-nav-active-item]"
                            />
                        )}
                        <span
                            className="flex [view-transition-class:mobile-nav-icon]"
                            style={{
                                viewTransitionName: `mobile-nav-${item.title.toLowerCase()}`,
                            }}
                        >
                            <NavIcon item={item} />
                        </span>
                    </span>
                    <span className="max-w-full truncate">{item.title}</span>
                </NavLink>
            ))}
            <DropdownMenu>
                <DropdownMenuTrigger
                    aria-label="More navigation"
                    className={cn(
                        'flex min-w-0 flex-1 flex-col items-center gap-1 py-1 text-[11px] font-medium tracking-tight text-muted-foreground',
                        {
                            'text-primary': menuItems.some(
                                (item) => item.isActive,
                            ),
                        },
                    )}
                >
                    <span
                        className={cn(
                            'flex h-8 w-14 max-w-full items-center justify-center rounded-lg',
                            {
                                'bg-primary-soft': menuItems.some(
                                    (item) => item.isActive,
                                ),
                            },
                        )}
                    >
                        <MobileMenuIcon className="size-5" />
                    </span>
                    <span>Menu</span>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    className="w-48 md:hidden"
                    side="top"
                    align="end"
                >
                    {menuItems.map((item) => (
                        <DropdownMenuItem key={item.title} asChild>
                            {auth.user === null ? (
                                <a href={toUrl(item.href)} className="min-h-11">
                                    {item.icon && <item.icon />}
                                    {item.title}
                                </a>
                            ) : (
                                <Link
                                    href={item.href}
                                    prefetch={item.prefetch ?? true}
                                    viewTransition
                                    aria-current={
                                        item.isActive ? 'page' : undefined
                                    }
                                    className={cn('min-h-11', {
                                        'bg-primary-soft text-primary':
                                            item.isActive,
                                    })}
                                >
                                    {item.icon && <item.icon />}
                                    {item.title}
                                </Link>
                            )}
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuItem
                        className="min-h-11"
                        onSelect={() => {
                            load();
                            setIsChangelogOpen(true);
                        }}
                    >
                        <History />
                        Changelog
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <ChangelogDialog
                open={isChangelogOpen}
                onOpenChange={setIsChangelogOpen}
                changelog={changelog}
                hasFailed={hasFailed}
                isLoadingOlder={isLoadingOlder}
                hasOlderFailed={hasOlderFailed}
                onLoadOlder={loadOlder}
                timeZone={timeZone}
            />
        </nav>
    );
}
