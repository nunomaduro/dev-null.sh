import { usePage } from '@inertiajs/react';
import { GitHubIcon } from '@/components/github-icon';
import { SignInAgreement } from '@/components/legal-links';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

/**
 * The aside's sign-in card, for guests on screens too narrow for the aside.
 */
export function GuestSignIn() {
    const { auth } = usePage().props;

    if (auth.user !== null) {
        return null;
    }

    return (
        <section
            aria-labelledby="feed-sign-in"
            className="border-b px-4 py-4 sm:px-6 xl:hidden"
        >
            <h2 id="feed-sign-in" className="font-semibold">
                New to /dev/null?
            </h2>
            <p className="mt-1 text-sm text-pretty text-muted-foreground">
                Sign in with GitHub to follow developers, star commits, and join
                the conversation.
            </p>
            <Button className="mt-3" asChild>
                <a href={login().url}>
                    <GitHubIcon className="size-4" />
                    Sign in with GitHub
                </a>
            </Button>
            <SignInAgreement className="mt-3 text-xs" />
        </section>
    );
}
