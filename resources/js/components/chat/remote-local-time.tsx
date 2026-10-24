import { LocalTime } from '@/components/local-time';
import type { User } from '@/types';

export function RemoteLocalTime({
    partner,
}: {
    partner: Pick<User, 'timezone' | 'show_local_time'>;
}) {
    if (!partner.show_local_time || !partner.timezone) {
        return null;
    }

    return (
        <ul
            title={partner.timezone}
            className="min-w-0 font-mono text-[11px] text-muted-foreground [&_li]:min-w-0 [&_li]:gap-1 [&_li_span]:truncate [&_svg]:size-3 [&_svg]:shrink-0"
            data-test="remote-local-time"
        >
            <LocalTime timeZone={partner.timezone} />
        </ul>
    );
}
