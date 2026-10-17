import { Share2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { shareProfile } from '@/lib/share-profile';

export function ProfileShareButton({
    username,
    href,
}: {
    username: string;
    href: string;
}) {
    const [, copy] = useClipboard();

    const onShare = async () => {
        const url = new URL(href, window.location.origin).href;
        const result = await shareProfile(
            { title: `@${username} on /dev/null`, url },
            navigator.share ? (data) => navigator.share(data) : undefined,
            copy,
        );

        if (result === 'copied') {
            toast('Profile link copied');
        } else if (result === 'failed') {
            toast.error('Could not share profile');
        }
    };

    return (
        <Button
            type="button"
            variant="outline"
            size="icon"
            onClick={onShare}
            aria-label={`Share @${username}'s profile`}
            title="Share profile"
            data-test="share-profile-button"
        >
            <Share2 />
        </Button>
    );
}
