import { Head } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import AppWordmark from '@/components/app-wordmark';
import { GitHubIcon } from '@/components/github-icon';
import { LiveStreamPlayer } from '@/components/live-stream';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { login } from '@/routes';

const commitStep = 820;
const lineDelay = 250;
const lineDuration = 450;
const joinDuration = 120;

function Commit({
    lane,
    previousLane,
    index,
    nodeTop,
    isStaged = false,
    isLast = false,
    children,
}: {
    lane: string;
    previousLane?: string;
    index: number;
    nodeTop: string;
    isStaged?: boolean;
    isLast?: boolean;
    children: ReactNode;
}) {
    const nodeDelay = index * commitStep;

    return (
        <li
            className="group/commit flex"
            style={
                {
                    '--lane': lane,
                    '--previous-lane': previousLane,
                    '--node-top': nodeTop,
                } as CSSProperties
            }
        >
            <div aria-hidden className="relative w-10 shrink-0 sm:w-16">
                {previousLane && (
                    <span
                        className="absolute top-0 left-1/2 h-(--node-top) w-0.5 origin-top -translate-x-1/2 bg-(--previous-lane) motion-safe:animate-rail-draw"
                        style={{
                            animationDelay: `${nodeDelay - joinDuration}ms`,
                            animationDuration: `${joinDuration}ms`,
                            animationTimingFunction: 'linear',
                        }}
                    />
                )}
                {!isLast && (
                    <span
                        className="absolute top-(--node-top) bottom-0 left-1/2 w-0.5 origin-top -translate-x-1/2 bg-(--lane) motion-safe:animate-rail-draw"
                        style={{
                            animationDelay: `${nodeDelay + lineDelay}ms`,
                            animationDuration: `${lineDuration}ms`,
                            animationTimingFunction: 'linear',
                        }}
                    />
                )}
                <span
                    className={cn(
                        'absolute top-(--node-top) left-1/2 size-4 -translate-1/2 rounded-full border-[3px] border-background bg-(--lane) shadow-[0_0_0_2px_var(--lane)] transition-colors duration-300 motion-safe:animate-node-in',
                        {
                            'bg-background group-focus-within/commit:bg-(--lane) group-hover/commit:bg-(--lane)':
                                isStaged,
                        },
                    )}
                    style={{ animationDelay: `${nodeDelay}ms` }}
                />
            </div>
            <div
                className={cn('min-w-0 flex-1', { 'pb-10 sm:pb-12': !isLast })}
            >
                {children}
            </div>
        </li>
    );
}

export default function Welcome() {
    return (
        <>
            <Head title="A social network for developers" />

            <div className="flex min-h-svh flex-col">
                <main className="mx-auto flex w-full max-w-4xl flex-1 items-center px-4 py-16 sm:px-8">
                    <ol className="w-full">
                        <Commit
                            lane="var(--lane-0)"
                            index={0}
                            nodeTop="clamp(1.75rem, 6vw, 3rem)"
                        >
                            <p className="flex items-center gap-[0.18em] text-[clamp(3.5rem,12vw,6rem)] leading-none font-semibold tracking-[-0.04em]">
                                <AppLogoIcon className="size-[0.78em] fill-current" />
                                <AppWordmark />
                            </p>
                        </Commit>

                        <Commit
                            lane="var(--lane-1)"
                            previousLane="var(--lane-0)"
                            index={1}
                            nodeTop="1.125rem"
                        >
                            <h1 className="text-[clamp(1.75rem,4vw,2.5rem)] leading-tight font-semibold tracking-tight text-balance">
                                A social network for developers.
                            </h1>
                            <p className="mt-3 max-w-[38ch] text-lg text-pretty text-muted-foreground">
                                Sign in with GitHub, follow people who write
                                code, and commit plain text to a shared log, 280
                                characters at a time. Everything else goes to
                                /dev/null.
                            </p>
                        </Commit>

                        <Commit
                            lane="var(--lane-2)"
                            previousLane="var(--lane-1)"
                            index={2}
                            nodeTop="0.625rem"
                        >
                            <LiveStreamPlayer className="max-w-2xl" />
                        </Commit>

                        <Commit
                            lane="var(--primary)"
                            previousLane="var(--lane-2)"
                            index={3}
                            nodeTop="1.375rem"
                            isStaged
                            isLast
                        >
                            <Button
                                size="lg"
                                className="h-11 px-5 text-[15px]"
                                asChild
                            >
                                <a href={login().url} data-test="login-button">
                                    <GitHubIcon className="size-5" />
                                    Sign in with GitHub
                                </a>
                            </Button>
                            <p className="mt-3 text-sm text-muted-foreground">
                                Your profile is imported from GitHub. Nothing to
                                fill in.
                            </p>
                        </Commit>
                    </ol>
                </main>
            </div>
        </>
    );
}
