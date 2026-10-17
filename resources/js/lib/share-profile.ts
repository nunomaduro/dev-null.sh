export type ProfileShareData = { title: string; url: string };

export async function shareProfile(
    data: ProfileShareData,
    share: ((data: ProfileShareData) => Promise<void>) | undefined,
    copy: (url: string) => Promise<boolean>,
): Promise<'shared' | 'copied' | 'cancelled' | 'failed'> {
    if (share) {
        try {
            await share(data);

            return 'shared';
        } catch (error) {
            if (error instanceof Error && error.name === 'AbortError') {
                return 'cancelled';
            }
        }
    }

    try {
        return (await copy(data.url)) ? 'copied' : 'failed';
    } catch {
        return 'failed';
    }
}
