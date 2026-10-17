import { Head } from '@inertiajs/react';
import { CopyableCode } from '@/components/copyable-code';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const workflow = [
    'name: /dev/null',
    '',
    'on:',
    '  release:',
    '    types: [published]',
    '',
    'permissions: {}',
    '',
    'jobs:',
    '  commit:',
    '    runs-on: ubuntu-latest',
    '    steps:',
    '      - uses: nunomaduro/dev-null.sh/.github/actions/commit@production',
    '        with:',
    '          body: |',
    '            Released ${{ github.event.release.name }}.',
    '            ${{ github.event.release.html_url }}',
    '          ssh-key: ${{ secrets.DEVNULL_SSH_KEY }}',
].join('\n');

const inputs = [
    { name: 'body', description: 'The commit, 1 to 4,000 characters.' },
    {
        name: 'ssh-key',
        description:
            'The private key, from a secret. Ed25519 or RSA, without a passphrase.',
    },
    {
        name: 'reply-to',
        description: 'Optional. The hash or link of a commit to reply to.',
    },
];

export default function Show({ url }: { url: string }) {
    return (
        <>
            <Head title="Terminal settings" />

            <section className="px-4 py-6 sm:px-6">
                <Heading
                    title="Commit from your terminal"
                    description="No login, no token: the script signs your commit with an SSH key that is on your GitHub account, and /dev/null checks it against the keys GitHub publishes for you."
                />

                <div className="space-y-3">
                    <CopyableCode
                        label="command"
                        code={`curl -fsSL ${url}/commit | bash -s -- 'It was DNS.'`}
                    />
                    <CopyableCode
                        label="reply command"
                        code={`curl -fsSL ${url}/commit | bash -s -- --reply 0be0130 'Agreed!'`}
                    />
                </div>
            </section>

            <section className="px-4 py-6 sm:px-6">
                <Heading
                    title="Commit from GitHub Actions"
                    description="Ship release notes when you publish a release, or anything else a workflow knows. Same signature, same moderation, same 10 commits a minute."
                />

                <ol className="space-y-4 text-sm">
                    <li className="space-y-2">
                        <p>
                            <StepNumber number={1} />
                            Generate a key for CI only.
                        </p>
                        <CopyableCode
                            label="key command"
                            code="ssh-keygen -t ed25519 -N '' -C devnull-ci -f devnull-ci"
                        />
                    </li>
                    <li>
                        <p className="max-w-prose text-pretty">
                            <StepNumber number={2} />
                            Add{' '}
                            <code className="font-mono text-[13px]">
                                devnull-ci.pub
                            </code>{' '}
                            to GitHub as a{' '}
                            <a
                                href="https://github.com/settings/ssh/new"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline decoration-muted-foreground/50 underline-offset-4 hover:decoration-foreground"
                            >
                                signing key
                            </a>
                            . It can sign /dev/null commits, but never push code
                            or log in.
                        </p>
                    </li>
                    <li>
                        <p className="max-w-prose text-pretty">
                            <StepNumber number={3} />
                            Save{' '}
                            <code className="font-mono text-[13px]">
                                devnull-ci
                            </code>
                            , the private key, as a repository secret named{' '}
                            <code className="font-mono text-[13px]">
                                DEVNULL_SSH_KEY
                            </code>
                            , then delete both files.
                        </p>
                    </li>
                    <li className="space-y-2">
                        <p>
                            <StepNumber number={4} />
                            Add the workflow, in{' '}
                            <code className="font-mono text-[13px]">
                                .github/workflows/devnull.yml
                            </code>
                            .
                        </p>
                        <CopyableCode
                            label="workflow"
                            language="yaml"
                            code={workflow}
                        />
                    </li>
                </ol>

                <dl className="mt-5 divide-y rounded-lg border text-sm">
                    {inputs.map((input) => (
                        <div
                            key={input.name}
                            className="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-3 px-4 py-2.5"
                        >
                            <dt className="font-mono text-[13px] text-muted-foreground">
                                {input.name}
                            </dt>
                            <dd className="text-pretty">{input.description}</dd>
                        </div>
                    ))}
                </dl>

                <p className="mt-4 max-w-prose text-sm text-pretty text-muted-foreground">
                    The job prints the link to your commit, and whether it is
                    pending review. When /dev/null refuses it, the job fails
                    with the reason.
                </p>
            </section>
        </>
    );
}

function StepNumber({ number }: { number: number }) {
    return (
        <span className="mr-2 font-mono text-muted-foreground">{number}.</span>
    );
}

Show.layout = [AppLayout, SettingsLayout];
