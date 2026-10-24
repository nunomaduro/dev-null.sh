import { Head } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { useUser } from '@/hooks/use-user';

type Props = {
    client: string;
    scopes: string[];
    authToken: string;
    state: string | null;
    clientId: string;
    csrfToken: string;
    url: string;
};

function Fields({
    authToken,
    state,
    clientId,
    csrfToken,
}: Pick<Props, 'authToken' | 'state' | 'clientId' | 'csrfToken'>) {
    return (
        <>
            <input type="hidden" name="_token" value={csrfToken} />
            <input type="hidden" name="auth_token" value={authToken} />
            <input type="hidden" name="client_id" value={clientId} />
            {state !== null && (
                <input type="hidden" name="state" value={state} />
            )}
        </>
    );
}

export default function Authorize({
    client,
    scopes,
    authToken,
    state,
    clientId,
    csrfToken,
    url,
}: Props) {
    const { user } = useUser();
    const fields = { authToken, state, clientId, csrfToken };

    return (
        <>
            <Head title="Authorize" />

            <main className="mx-auto flex min-h-svh w-full max-w-md flex-col justify-center gap-8 px-4 py-16">
                <AppLogo />

                <div>
                    <h1 className="text-2xl leading-tight font-semibold tracking-tight text-balance">
                        Let {client} use your devnull account?
                    </h1>
                    <p className="mt-3 text-muted-foreground">
                        You are signed in as @{user.username}. {client} will be
                        able to:
                    </p>
                    <ul className="mt-4 list-disc space-y-1 pl-5">
                        {scopes.map((scope) => (
                            <li key={scope}>{scope}</li>
                        ))}
                    </ul>
                </div>

                {/* Plain forms: the browser has to follow the redirect back to the client itself. */}
                <div className="flex gap-3">
                    <form method="post" action={url}>
                        <Fields {...fields} />
                        <Button type="submit">Authorize</Button>
                    </form>
                    <form method="post" action={url}>
                        <Fields {...fields} />
                        <input type="hidden" name="_method" value="DELETE" />
                        <Button type="submit" variant="outline">
                            Cancel
                        </Button>
                    </form>
                </div>
            </main>
        </>
    );
}
