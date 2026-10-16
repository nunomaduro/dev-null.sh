import { Head } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { useState } from 'react';
import MobileAuthorizationController from '@/actions/App/Http/Controllers/MobileAuthorizationController';
import AppLogo from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { UserAvatar } from '@/components/user-avatar';
import { useUser } from '@/hooks/use-user';
import { laneColor } from '@/lib/utils';

type Authorization = {
    fingerprint: string;
    device_name: string;
    code_challenge: string;
    code_challenge_method: string;
    state: string;
    redirect_uri: string;
};

type Props =
    | { error: string }
    | { authorization: Authorization; cancelUrl: string; csrfToken: string };

export default function Show(props: Props) {
    return 'error' in props ? (
        <Invalid error={props.error} />
    ) : (
        <Approve {...props} />
    );
}

function Approve({
    authorization,
    cancelUrl,
    csrfToken,
}: {
    authorization: Authorization;
    cancelUrl: string;
    csrfToken: string;
}) {
    const { user } = useUser();
    const [isApproving, setIsApproving] = useState(false);

    return (
        <Page
            title={`Approve ${authorization.device_name}`}
            lane={laneColor(user.lane)}
            command="git credential approve"
        >
            <h1 className="mt-4 text-[clamp(1.75rem,4vw,2.5rem)] leading-tight font-semibold tracking-tight text-balance [overflow-wrap:anywhere]">
                Approve {authorization.device_name}?
            </h1>

            <dl className="mt-6 grid max-w-md gap-4">
                <div>
                    <dt className="text-sm text-muted-foreground">
                        Signs in as
                    </dt>
                    <dd className="mt-1.5 flex min-w-0 items-center gap-3">
                        <UserAvatar user={user} className="size-10" />
                        <span className="min-w-0">
                            <span
                                className="block truncate font-semibold"
                                title={user.name}
                            >
                                {user.name}
                            </span>
                            <span
                                className="block truncate font-mono text-[13px] text-muted-foreground"
                                title={`@${user.username}`}
                            >
                                @{user.username}
                            </span>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-muted-foreground">With key</dt>
                    <dd className="mt-1.5 rounded-md border bg-muted/50 px-3 py-2 font-mono text-[13px] [overflow-wrap:anywhere]">
                        {authorization.fingerprint}
                    </dd>
                </div>
            </dl>

            <p className="mt-6 max-w-[48ch] text-pretty text-muted-foreground">
                The app will be able to use /dev/null as you. You can revoke it
                any time under Settings → Devices.
            </p>

            {/* A plain form, so the browser follows the redirect back into the app... */}
            <form
                {...MobileAuthorizationController.store.form()}
                onSubmit={() => setIsApproving(true)}
                className="mt-8 flex flex-wrap gap-2"
            >
                <input type="hidden" name="_token" value={csrfToken} />
                {Object.entries(authorization).map(([name, value]) => (
                    <input key={name} type="hidden" name={name} value={value} />
                ))}
                <Button
                    type="submit"
                    size="lg"
                    disabled={isApproving}
                    className="min-w-28"
                >
                    Approve
                </Button>
                <Button variant="outline" size="lg" asChild>
                    <a href={cancelUrl}>Cancel</a>
                </Button>
            </form>
        </Page>
    );
}

function Invalid({ error }: { error: string }) {
    return (
        <Page
            title="Sign-in link not valid"
            lane="var(--rail)"
            command="fatal: invalid authorization request"
        >
            <h1 className="mt-4 text-[clamp(1.75rem,4vw,2.5rem)] leading-tight font-semibold tracking-tight text-balance">
                This sign-in link isn't valid.
            </h1>
            <p className="mt-3 max-w-[42ch] text-lg text-pretty text-muted-foreground">
                {error} Start signing in again from the /dev/null app.
            </p>
        </Page>
    );
}

function Page({
    title,
    lane,
    command,
    children,
}: {
    title: string;
    lane: string;
    command: string;
    children: ReactNode;
}) {
    return (
        <>
            <Head title={title} />

            <main
                className="mx-auto flex min-h-svh w-full max-w-3xl items-center px-4 py-16 sm:px-8"
                style={{ '--lane': lane } as CSSProperties}
            >
                <ol className="w-full">
                    <li className="flex">
                        <div
                            aria-hidden
                            className="relative w-10 shrink-0 sm:w-16"
                        >
                            <span className="absolute top-3.5 bottom-6 left-1/2 w-0.5 -translate-x-1/2 bg-(--lane) mask-b-from-40%" />
                            <span className="absolute top-3.5 left-1/2 size-3.5 -translate-1/2 rounded-full border-[3px] border-background bg-(--lane) shadow-[0_0_0_2px_var(--lane)]" />
                        </div>
                        <div className="min-w-0 flex-1 pb-14 sm:pb-16">
                            <AppLogo />
                        </div>
                    </li>

                    <li className="flex">
                        <div
                            aria-hidden
                            className="relative w-10 shrink-0 sm:w-16"
                        >
                            <span className="absolute top-2.5 left-1/2 size-3.5 -translate-1/2 rounded-full border-2 border-muted-foreground bg-background" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="font-mono text-[13px] [overflow-wrap:anywhere] text-muted-foreground">
                                {command}
                            </p>
                            {children}
                        </div>
                    </li>
                </ol>
            </main>
        </>
    );
}
