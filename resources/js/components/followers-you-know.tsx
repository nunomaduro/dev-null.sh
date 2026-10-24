import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import UserFollowersYouKnowController from '@/actions/App/Http/Controllers/UserFollowersYouKnowController';
import { UserAvatar } from '@/components/user-avatar';
import { show } from '@/routes/user';
import type { User } from '@/types';

export type FollowersYouKnowPreview = {
    users: User[];
    count: number;
};

/**
 * Up to two people are named, and the rest are counted, so the line stays one sentence however many there are.
 */
const NAMED = 2;

/**
 * Social proof under a profile's counts: which of the people the viewer follows also follow this user.
 */
export function FollowersYouKnow({
    user,
    preview,
}: {
    user: Pick<User, 'username'>;
    preview: FollowersYouKnowPreview;
}) {
    if (preview.count === 0) {
        return null;
    }

    const named = preview.users.slice(0, NAMED);
    const others = preview.count - named.length;
    const list = UserFollowersYouKnowController.index(user);

    return (
        <div
            className="mt-3 flex items-center gap-2.5 text-sm text-muted-foreground"
            data-test="followers-you-know"
        >
            <Link
                href={list}
                aria-label="Followers you know"
                className="flex shrink-0 -space-x-1.5 rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {preview.users.map((person) => (
                    <UserAvatar
                        key={person.id}
                        user={person}
                        pixels={40}
                        className="size-5 text-[10px] ring-2 ring-card"
                    />
                ))}
            </Link>
            <p className="min-w-0 leading-snug">
                Followed by{' '}
                {named.map((person, index) => (
                    <Fragment key={person.id}>
                        {index > 0 && (others > 0 ? ', ' : ' and ')}
                        <Link
                            href={show(person)}
                            className="font-mono text-[13px] font-medium wrap-anywhere text-foreground hover:underline"
                        >
                            @{person.username}
                        </Link>
                    </Fragment>
                ))}
                {others > 0 && (
                    <>
                        {' '}
                        and{' '}
                        <Link href={list} className="hover:underline">
                            {others} {others === 1 ? 'other' : 'others'} you
                            follow
                        </Link>
                    </>
                )}
            </p>
        </div>
    );
}
